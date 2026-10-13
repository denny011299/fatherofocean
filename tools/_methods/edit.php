public function edit(Barang $barang): View
    {
        abort_unless(auth()->user()->can('edit barang'), 403);

        $barang->loadCount('products');

        return view('barang.form', array_merge($this->formViewData(), [
            'barang' => $barang,
            'barangFormPayload' => $this->barangFormPayload($barang),
        ]));
    }