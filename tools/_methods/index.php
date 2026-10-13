public function index(Request $request): View
    {
        $query = Barang::with('supplier', 'customer', 'dimensions');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('no_index', 'like', "%{$search}%")
                  ->orWhere('marking', 'like', "%{$search}%")
                  ->orWhere('no_po', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($warehouse = $request->get('warehouse')) {
            $query->where('warehouse_origin', $warehouse);
        }

        $barangs = $query->latest()->paginate(20);
        $suppliers = Supplier::where('is_active', true)->get();
        $customers = User::role('customer')->get();
        $departments = MasterDepartment::active()->orderBy('name')->get();
        $branches = MasterBranch::active()->orderBy('code')->get();
        $destinations = City::active()->orderBy('name')->get();
        $packagingTypes = MasterPackagingType::active()->orderBy('name')->get();
        $cbmTypes = MasterCbmType::active()->orderBy('name')->get();
        $classifications = MasterClassification::active()->orderBy('name')->get();
        $countUnits = MasterCountUnit::active()->orderBy('name')->get();

        $markings = Marking::active()->with('customer')->orderBy('code')->get();

        return view('barang.index', compact(
            'barangs', 'suppliers', 'customers', 'departments',
            'branches', 'destinations', 'packagingTypes',
            'cbmTypes', 'classifications', 'countUnits', 'markings'
        ));
    }