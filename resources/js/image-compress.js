/**
 * Optional client-side image compression — only when input has data-compress-image="true".
 */

const DEFAULTS = {
    maxWidth: 1280,
    maxHeight: 1280,
    quality: 0.76,
    skipBelowBytes: 120_000,
    outputType: 'image/jpeg',
};

/** KTP / marking photo — aggressive compress for fast upload (Pegasus-style). */
export const MARKING_KTP_COMPRESS = {
    maxWidth: 1280,
    maxHeight: 1280,
    quality: 0.72,
    skipBelowBytes: 80_000,
    outputType: 'image/jpeg',
};

/** Bukti transfer pembayaran — ringan untuk upload cepat. */
export const PAYMENT_PROOF_COMPRESS = {
    maxWidth: 1600,
    maxHeight: 1600,
    quality: 0.78,
    skipBelowBytes: 120_000,
    outputType: 'image/jpeg',
    forceJpeg: true,
};

/** Bukti foto pengiriman — terima hingga 10 MB, kompres ke bawah 5 MB sebelum upload. */
export const DELIVERY_TRIP_INPUT_MAX_BYTES = 10 * 1024 * 1024;

export const DELIVERY_TRIP_TARGET_MAX_BYTES = 5 * 1024 * 1024;

/** Foto produk di form barang — terima 10 MB, kompres di bawah 5 MB. */
export const BARANG_PRODUCT_INPUT_MAX_BYTES = DELIVERY_TRIP_INPUT_MAX_BYTES;

export const BARANG_PRODUCT_COMPRESS = {
    maxWidth: 1600,
    maxHeight: 1600,
    quality: 0.78,
    skipBelowBytes: 80_000,
    maxBytes: DELIVERY_TRIP_TARGET_MAX_BYTES,
    outputType: 'image/jpeg',
    forceJpeg: true,
};

export const DELIVERY_TRIP_PHOTO_COMPRESS = {
    maxWidth: 1600,
    maxHeight: 1600,
    quality: 0.78,
    skipBelowBytes: 80_000,
    maxBytes: DELIVERY_TRIP_TARGET_MAX_BYTES,
    outputType: 'image/jpeg',
    forceJpeg: true,
};

/** Good receipt di form barang — sama: terima 10 MB, kompres di bawah 5 MB. */
export const BARANG_RECEIPT_INPUT_MAX_BYTES = DELIVERY_TRIP_INPUT_MAX_BYTES;

export const BARANG_RECEIPT_COMPRESS = {
    ...DELIVERY_TRIP_PHOTO_COMPRESS,
};

const COMPRESS_PRESETS = {
    payment: PAYMENT_PROOF_COMPRESS,
    ktp: MARKING_KTP_COMPRESS,
    barang: BARANG_PRODUCT_COMPRESS,
    delivery: DELIVERY_TRIP_PHOTO_COMPRESS,
};

let pendingCompressCount = 0;
let idleResolvers = [];

function beginCompression() {
    pendingCompressCount += 1;
}

function endCompression() {
    pendingCompressCount = Math.max(0, pendingCompressCount - 1);
    if (pendingCompressCount === 0 && idleResolvers.length) {
        const resolvers = idleResolvers;
        idleResolvers = [];
        resolvers.forEach((resolve) => resolve());
    }
}

export function hasPendingImageCompression() {
    return pendingCompressCount > 0;
}

export function waitForImageCompression() {
    if (pendingCompressCount === 0) {
        return Promise.resolve();
    }
    return new Promise((resolve) => {
        idleResolvers.push(resolve);
    });
}

export function shouldCompressFile(file) {
    if (!file || !file.type) {
        return false;
    }
    return file.type.startsWith('image/') && file.type !== 'image/gif' && file.type !== 'image/svg+xml';
}

function shouldCompressInput(input) {
    if (!input || input.type !== 'file') {
        return false;
    }

    return input.dataset.compressImage === 'true';
}

function loadImageFromFile(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Failed to load image'));
        };
        img.src = url;
    });
}

function canvasToBlob(canvas, type, quality) {
    return new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), type, quality);
    });
}

function scaledSize(width, height, maxW, maxH) {
    const ratio = Math.min(1, maxW / width, maxH / height);
    return {
        width: Math.max(1, Math.round(width * ratio)),
        height: Math.max(1, Math.round(height * ratio)),
    };
}

function buildOutputName(file, mime) {
    const base = (file.name || 'image').replace(/\.[^.]+$/, '');
    const ext = mime === 'image/png' ? 'png' : mime === 'image/webp' ? 'webp' : 'jpg';
    return base + '.' + ext;
}

function resolveOutputType(file, opts) {
    if (opts.forceJpeg) {
        return 'image/jpeg';
    }
    if (file.type === 'image/png' && opts.outputType !== 'image/jpeg') {
        return 'image/png';
    }
    if (file.type === 'image/webp' && opts.outputType !== 'image/jpeg') {
        return 'image/webp';
    }

    return opts.outputType;
}

function buildCompressionAttempts(opts) {
    const attempts = [
        {
            maxWidth: opts.maxWidth,
            maxHeight: opts.maxHeight,
            quality: opts.quality,
        },
    ];

    if (!opts.maxBytes) {
        return attempts;
    }

    attempts.push(
        { maxWidth: opts.maxWidth, maxHeight: opts.maxHeight, quality: 0.62 },
        { maxWidth: 1280, maxHeight: 1280, quality: opts.quality },
        { maxWidth: 1280, maxHeight: 1280, quality: 0.58 },
        { maxWidth: 1024, maxHeight: 1024, quality: 0.55 },
    );

    return attempts;
}

async function renderCompressedBlob(img, attempt, outputType) {
    const size = scaledSize(img.width, img.height, attempt.maxWidth, attempt.maxHeight);
    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return null;
    }
    ctx.drawImage(img, 0, 0, size.width, size.height);

    return canvasToBlob(canvas, outputType, attempt.quality);
}

function fileFromBlob(blob, file, outputType) {
    return new File([blob], buildOutputName(file, outputType), {
        type: outputType,
        lastModified: Date.now(),
    });
}

export async function compressImageFile(file, options = {}) {
    const opts = { ...DEFAULTS, ...options };
    if (!shouldCompressFile(file)) {
        return file;
    }

    const outputType = resolveOutputType(file, opts);
    const needsTarget = !!opts.maxBytes && file.size > opts.maxBytes;
    if (file.size < opts.skipBelowBytes && !needsTarget) {
        return file;
    }

    try {
        const img = await loadImageFromFile(file);
        const attempts = buildCompressionAttempts(opts);
        let bestBlob = null;

        for (const attempt of attempts) {
            const blob = await renderCompressedBlob(img, attempt, outputType);
            if (!blob) {
                continue;
            }

            if (opts.maxBytes && blob.size <= opts.maxBytes) {
                return fileFromBlob(blob, file, outputType);
            }

            if (!bestBlob || blob.size < bestBlob.size) {
                bestBlob = blob;
            }

            if (!opts.maxBytes && blob.size < file.size) {
                return fileFromBlob(blob, file, outputType);
            }
        }

        if (bestBlob && bestBlob.size < file.size) {
            return fileFromBlob(bestBlob, file, outputType);
        }

        return file;
    } catch {
        return file;
    }
}

/**
 * Kompres banyak foto bukti pengiriman secara paralel (maks. 10 MB input, target < 5 MB).
 *
 * @param {FileList|File[]} fileList
 * @param {{ inputMaxBytes?: number }} [options]
 * @returns {Promise<File[]>}
 */
export async function compressDeliveryTripPhotos(fileList, options = {}) {
    const inputMaxBytes = options.inputMaxBytes ?? DELIVERY_TRIP_INPUT_MAX_BYTES;
    const files = Array.from(fileList || []);

    return Promise.all(
        files.map(async (file) => {
            if (file.size > inputMaxBytes) {
                throw new Error('photo_too_large');
            }

            try {
                return await compressImageFile(file, DELIVERY_TRIP_PHOTO_COMPRESS);
            } catch {
                return file;
            }
        }),
    );
}

export function replaceInputFile(input, newFile) {
    const dt = new DataTransfer();
    dt.items.add(newFile);
    input.files = dt.files;
}

export function replaceInputFiles(input, files) {
    const dt = new DataTransfer();
    files.forEach((file) => dt.items.add(file));
    input.files = dt.files;
}

export function initImageCompress(root = document) {
    root.addEventListener(
        'change',
        async (event) => {
            const input = event.target;
            if (!shouldCompressInput(input) || input.dataset.compressing === '1') {
                return;
            }
            const files = Array.from(input.files || []);
            if (files.length === 0) {
                return;
            }

            input.dataset.compressing = '1';
            beginCompression();
            try {
                const compressedFiles = [];
                let changed = false;
                for (const file of files) {
                    if (!shouldCompressFile(file)) {
                        compressedFiles.push(file);
                        continue;
                    }

                    const preset = COMPRESS_PRESETS[input.dataset.compressProfile || ''] || {};
                    const compressed = await compressImageFile(file, preset);
                    if (compressed !== file && compressed.size < file.size) {
                        changed = true;
                        compressedFiles.push(compressed);
                    } else {
                        compressedFiles.push(file);
                    }
                }

                if (changed) {
                    replaceInputFiles(input, compressedFiles);
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } finally {
                endCompression();
                delete input.dataset.compressing;
            }
        },
        true,
    );
}
