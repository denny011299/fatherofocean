private function stripEmptyProductPhotoFields(Request $request): void
    {
        $products = $request->file('products');
        if (! is_array($products)) {
            return;
        }

        foreach ($products as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (Barang::productUploadFileKeys() as $fileKey) {
                if (! isset($row[$fileKey])) {
                    continue;
                }

                $file = $row[$fileKey];
                if (! $file instanceof \Illuminate\Http\UploadedFile || ! $file->isValid()) {
                    $request->files->remove("products.{$index}.{$fileKey}");
                }
            }
        }
    }