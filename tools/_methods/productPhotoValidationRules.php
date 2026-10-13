private function productPhotoValidationRules(Request $request): array
    {
        $rules = [];
        $products = $request->file('products');
        if (! is_array($products)) {
            return $rules;
        }

        foreach ($products as $index => $row) {
            if (! is_array($row) || ! isset($row['photo'])) {
                continue;
            }

            $file = $row['photo'];
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                $rules["products.{$index}.photo"] = 'file|mimes:jpg,jpeg,png,webp|max:5120';
            }
        }

        return $rules;
    }