public function import(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => 'required|mimes:xlsx,xls,csv|max:10240'],
            [],
            $this->barangValidationAttributes()
        );

        try {
            Excel::import(new \App\Imports\BarangImport(), $request->file('file'));

            return response()->json(['success' => true, 'message' => 'Data preload berhasil diimport.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal import: ' . $e->getMessage()], 422);
        }
    }