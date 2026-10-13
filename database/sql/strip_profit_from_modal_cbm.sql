-- Preview saja (opsional, sebelum APPLY)
SELECT name, cost_volume_price AS sekarang, cost_volume_price - 150000 AS sesudah
FROM company_revenue_item_modals m
LEFT JOIN master_pricing_items i ON i.id = m.pricing_item_id;
