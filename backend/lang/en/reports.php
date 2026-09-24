<?php

return ['columns' => [
    'created_at' => 'Created At', 'movement_type' => 'Movement Type',
    'quantity' => 'Quantity', 'quantity_in' => 'Quantity In', 'quantity_out' => 'Quantity Out',
    'source_type' => 'Source Type', 'source_label' => 'Source / Reference',
    'user_name' => 'User Name', 'product_name' => 'Product Name',
    'product_barcode' => 'Product Barcode', 'batch_number' => 'Batch Number',
    'batch_expiry' => 'Batch Expiry', 'branch' => 'Branch', 'cost_basis' => 'Unit Cost',
    'party_name' => 'Customer', 'invoice_number' => 'Invoice Number', 'invoice_date' => 'Invoice Date',
    'due_date' => 'Due Date', 'original_amount' => 'Original Amount', 'settled_amount' => 'Settled Amount',
    'outstanding_amount' => 'Outstanding Amount', 'age_days' => 'Age Days', 'bucket' => 'Aging Bucket',
    'party_id' => 'Party ID', 'invoice_id' => 'Invoice ID', 'aging_basis' => 'Aging Basis',
], 'values' => [
    'bucket' => ['current_not_due' => 'Current / Not Due', '0_30' => '0–30 Days', '31_60' => '31–60 Days', '61_90' => '61–90 Days', 'over_90' => 'More than 90 Days'],
    'aging_basis' => ['invoice_allocation' => 'Invoice allocation', 'legacy_pooled_allocation' => 'Pooled balance allocation'],
]];
