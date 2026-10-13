private function storeOriginalPackingListFile(\Illuminate\Http\UploadedFile $file, string $noIndex): string
    {
        return \App\Support\BarangOriginalPackingList::store($file, $noIndex);
    }