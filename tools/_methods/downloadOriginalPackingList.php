public function downloadOriginalPackingList(Barang $barang): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(auth()->user()?->can('view barang'), 403);

        $path = $barang->invoice_file;
        if ($path === null || $path === '' || ! Storage::disk('public')->exists($path)) {
            abort(404, __('messages.file_not_found'));
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'xlsx';
        $downloadName = \App\Support\BarangOriginalPackingList::filename((string) $barang->no_index, $extension);

        return Storage::disk('public')->download($path, $downloadName);
    }