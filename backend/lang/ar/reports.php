<?php

return ['columns' => [
    'created_at' => 'تاريخ الإنشاء', 'movement_type' => 'نوع الحركة',
    'quantity' => 'الكمية', 'quantity_in' => 'الكمية الواردة', 'quantity_out' => 'الكمية الصادرة',
    'source_type' => 'نوع المصدر', 'source_label' => 'المرجع / بيان المصدر',
    'user_name' => 'اسم المستخدم', 'product_name' => 'اسم المنتج',
    'product_barcode' => 'باركود المنتج', 'batch_number' => 'رقم التشغيلة',
    'batch_expiry' => 'تاريخ انتهاء التشغيلة', 'branch' => 'الفرع', 'cost_basis' => 'تكلفة الوحدة',
    'party_name' => 'العميل', 'invoice_number' => 'رقم الفاتورة', 'invoice_date' => 'تاريخ الفاتورة',
    'due_date' => 'تاريخ الاستحقاق', 'original_amount' => 'المبلغ الأصلي', 'settled_amount' => 'المبلغ المسدد',
    'outstanding_amount' => 'المبلغ المستحق', 'age_days' => 'أيام الاستحقاق', 'bucket' => 'فئة عمر الدين',
    'party_id' => 'معرّف الطرف', 'invoice_id' => 'معرّف الفاتورة', 'aging_basis' => 'أساس أعمار الديون',
], 'values' => [
    'bucket' => ['current_not_due' => 'غير مستحق حاليًا', '0_30' => '0–30 يوم', '31_60' => '31–60 يوم', '61_90' => '61–90 يوم', 'over_90' => 'أكثر من 90 يوم'],
    'aging_basis' => ['invoice_allocation' => 'تخصيص الفواتير', 'legacy_pooled_allocation' => 'تخصيص الرصيد المجمع'],
]];
