# Packing list Excel template (required for container export)

Place the commercial invoice / packing list template here:

```
packing-list-commercial-invoice-template.xls
```

The file must contain two worksheets named exactly:

- `发票` (invoice)
- `箱单` (packing list)

Optional `.env` override:

```
PACKING_LIST_TEMPLATE_PATH=/absolute/path/to/template.xls
```

After deploy:

```bash
php artisan config:clear
```
