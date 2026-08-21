<?php

return [
    'navigation' => 'ملفي',
    'title' => 'ملفي',
    'sections' => [
        'contact' => 'بيانات الاتصال الخاصة بي',
        'account' => 'حالة الحساب',
        'password' => 'تغيير كلمة المرور',
    ],
    'fields' => [
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الهاتف (E.164)',
        'locale' => 'اللغة',
        'email_verification' => 'التحقق من البريد الإلكتروني',
        'phone_verification' => 'التحقق من رقم الهاتف',
        'status' => 'الحالة',
        'roles' => 'الأدوار في هذه المؤسسة',
        'current_password' => 'كلمة المرور الحالية',
        'password' => 'كلمة المرور الجديدة',
        'password_confirmation' => 'تأكيد كلمة المرور الجديدة',
    ],
    'locales' => ['fr' => 'Français', 'ar' => 'العربية'],
    'statuses' => [
        'active' => 'نشط',
        'suspended' => 'موقوف',
        'deletion_requested' => 'طُلب حذف الحساب',
        'anonymized' => 'مجهول الهوية',
    ],
    'verification' => ['verified' => 'تم التحقق', 'unverified' => 'غير متحقق'],
    'actions' => [
        'save_account' => 'حفظ بيانات الاتصال',
        'save_password' => 'تغيير كلمة المرور',
    ],
    'notifications' => [
        'account_saved' => 'تم حفظ بيانات الاتصال الخاصة بك.',
        'password_saved' => 'تم تغيير كلمة المرور الخاصة بك.',
    ],
];
