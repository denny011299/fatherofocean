public function create(Request $request): View
    {
        abort_unless(auth()->user()->can('create barang'), 403);

        return view('barang.form', array_merge($this->formViewData(), [
            'barang' => null,
            'barangFormPayload' => null,
        ]));
    }