<?php

/*
|--------------------------------------------------------------------------
| Messages de validation — arabe
|--------------------------------------------------------------------------
|
| PAS-3.1. Pendant du fichier français, traduit en entier pour la même raison :
| une clé brute affichée à l'écran est une régression que rien ne signale.
|
| ATTENTION au repli. `fallback_locale` vaut `fr` : une clé manquante ici
| n'afficherait pas « validation.min.string » mais la phrase FRANÇAISE, dans
| une interface par ailleurs entièrement arabe et en lecture de droite à
| gauche. Le défaut serait donc plus discret que côté français, et d'autant
| plus durable. C'est pourquoi aucune clé n'est laissée de côté.
|
| Les messages tutoient l'usage courant marocain de l'arabe standard : phrases
| courtes, pas de vocabulaire juridique inutile.
|
*/

return [

    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => 'يجب أن يكون :attribute رابطا صالحا.',
    'after' => 'يجب أن يكون :attribute تاريخا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخا بعد أو يساوي :date.',
    'alpha' => 'يجب ألا يحتوي :attribute إلا على حروف.',
    'alpha_dash' => 'يجب ألا يحتوي :attribute إلا على حروف وأرقام وشرطات وشرطات سفلية.',
    'alpha_num' => 'يجب ألا يحتوي :attribute إلا على حروف وأرقام.',
    'any_of' => ':attribute غير صالح.',
    'array' => 'يجب أن يكون :attribute قائمة.',
    'ascii' => 'يجب ألا يحتوي :attribute إلا على حروف وأرقام ورموز أحادية البايت.',
    'before' => 'يجب أن يكون :attribute تاريخا قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخا قبل أو يساوي :date.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و:max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و:max.',
        'string' => 'يجب أن يحتوي :attribute على عدد أحرف بين :min و:max.',
    ],
    'boolean' => 'يجب أن يكون :attribute صحيحا أو خاطئا.',
    'can' => 'يحتوي :attribute على قيمة غير مسموح بها.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'contains' => 'ينقص :attribute قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'يجب أن يكون :attribute تاريخا صالحا.',
    'date_equals' => 'يجب أن يكون :attribute تاريخا يساوي :date.',
    'date_format' => 'يجب أن يوافق :attribute الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منازل عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'digits' => 'يجب أن يحتوي :attribute على :digits أرقام.',
    'digits_between' => 'يجب أن يحتوي :attribute على عدد أرقام بين :min و:max.',
    'dimensions' => 'أبعاد الصورة :attribute غير صالحة.',
    'distinct' => 'يحتوي :attribute على قيمة مكررة.',
    'doesnt_contain' => 'يجب ألا يحتوي :attribute على أي من القيم التالية: :values.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأي من القيم التالية: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأي من القيم التالية: :values.',
    'email' => 'يجب أن يكون :attribute بريدا إلكترونيا صالحا.',
    'encoding' => 'يجب أن يكون ترميز :attribute هو :encoding.',
    'ends_with' => 'يجب أن ينتهي :attribute بإحدى القيم التالية: :values.',
    'enum' => 'القيمة المختارة لـ :attribute غير صالحة.',
    'exists' => 'القيمة المختارة لـ :attribute غير صالحة.',
    'extensions' => 'يجب أن يحمل :attribute إحدى الامتدادات التالية: :values.',
    'file' => 'يجب أن يكون :attribute ملفا.',
    'filled' => 'يجب أن تكون لـ :attribute قيمة.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عناصر.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يحتوي :attribute على أكثر من :value أحرف.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عناصر أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكثر.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من أو تساوي :value.',
        'string' => 'يجب أن يحتوي :attribute على :value أحرف أو أكثر.',
    ],
    'hex_color' => 'يجب أن يكون :attribute لونا سداسي عشري صالحا.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة لـ :attribute غير صالحة.',
    'in_array' => 'يجب أن يوجد :attribute ضمن :other.',
    'in_array_keys' => 'يجب أن يحتوي :attribute على واحد على الأقل من المفاتيح التالية: :values.',
    'integer' => 'يجب أن يكون :attribute عددا صحيحا.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صالحا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صالحا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صالحا.',
    'json' => 'يجب أن يكون :attribute نص JSON صالحا.',
    'list' => 'يجب أن يكون :attribute قائمة.',
    'lowercase' => 'يجب أن يكون :attribute بحروف صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عناصر.',
        'file' => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
        'string' => 'يجب أن يحتوي :attribute على أقل من :value أحرف.',
    ],
    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عناصر.',
        'file' => 'يجب ألا يتجاوز حجم :attribute :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من أو تساوي :value.',
        'string' => 'يجب ألا يحتوي :attribute على أكثر من :value أحرف.',
    ],
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صالحا.',
    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عناصر.',
        'file' => 'يجب ألا يتجاوز حجم :attribute :max كيلوبايت.',
        'numeric' => 'يجب ألا تتجاوز قيمة :attribute :max.',
        'string' => 'يجب ألا يتجاوز :attribute :max حرفا.',
    ],
    'max_digits' => 'يجب ألا يحتوي :attribute على أكثر من :max أرقام.',
    'mimes' => 'يجب أن يكون :attribute ملفا من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفا من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عناصر على الأقل.',
        'file' => 'يجب أن يكون حجم :attribute :min كيلوبايت على الأقل.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب أن يحتوي :attribute على :min أحرف على الأقل.',
    ],
    'min_digits' => 'يجب أن يحتوي :attribute على :min أرقام على الأقل.',
    'missing' => 'يجب ألا يكون :attribute موجودا.',
    'missing_if' => 'يجب ألا يكون :attribute موجودا عندما يكون :other هو :value.',
    'missing_unless' => 'يجب ألا يكون :attribute موجودا إلا إذا كان :other هو :value.',
    'missing_with' => 'يجب ألا يكون :attribute موجودا عند وجود :values.',
    'missing_with_all' => 'يجب ألا يكون :attribute موجودا عند وجود :values.',
    'multiple_of' => 'يجب أن يكون :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المختارة لـ :attribute غير صالحة.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => 'يجب أن يكون :attribute رقما.',
    'password' => [
        'letters' => 'يجب أن يحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي :attribute على رمز واحد على الأقل.',

        // Voir la note du fichier français : on explique sans culpabiliser.
        'uncompromised' => 'كلمة المرور هذه ظهرت في تسريب بيانات معروف. اختر كلمة مرور أخرى.',
    ],
    'present' => 'يجب أن يكون :attribute موجودا.',
    'present_if' => 'يجب أن يكون :attribute موجودا عندما يكون :other هو :value.',
    'present_unless' => 'يجب أن يكون :attribute موجودا إلا إذا كان :other هو :value.',
    'present_with' => 'يجب أن يكون :attribute موجودا عند وجود :values.',
    'present_with_all' => 'يجب أن يكون :attribute موجودا عند وجود :values.',
    'prohibited' => ':attribute غير مسموح به.',
    'prohibited_if' => ':attribute غير مسموح به عندما يكون :other هو :value.',
    'prohibited_if_accepted' => ':attribute غير مسموح به عندما يكون :other مقبولا.',
    'prohibited_if_declined' => ':attribute غير مسموح به عندما يكون :other مرفوضا.',
    'prohibited_unless' => ':attribute غير مسموح به إلا إذا كان :other ضمن :values.',
    'prohibits' => ':attribute يمنع وجود :other.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => ':attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على مدخلات لـ: :values.',
    'required_if' => ':attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => ':attribute مطلوب عندما يكون :other مقبولا.',
    'required_if_declined' => ':attribute مطلوب عندما يكون :other مرفوضا.',
    'required_unless' => ':attribute مطلوب إلا إذا كان :other ضمن :values.',
    'required_with' => ':attribute مطلوب عند وجود :values.',
    'required_with_all' => ':attribute مطلوب عند وجود :values.',
    'required_without' => ':attribute مطلوب عند غياب :values.',
    'required_without_all' => ':attribute مطلوب عند غياب كل من :values.',
    'same' => 'يجب أن يطابق :attribute قيمة :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عناصر.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تساوي قيمة :attribute :size.',
        'string' => 'يجب أن يحتوي :attribute على :size حرفا.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بإحدى القيم التالية: :values.',
    'string' => 'يجب أن يكون :attribute نصا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صالحة.',
    'unique' => ':attribute مستعمل من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بحروف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطا صالحا.',
    'ulid' => 'يجب أن يكون :attribute ULID صالحا.',
    'uuid' => 'يجب أن يكون :attribute UUID صالحا.',

    /*
    |--------------------------------------------------------------------------
    | Messages propres à un champ
    |--------------------------------------------------------------------------
    |
    | Mêmes réécritures que côté français, là où le gabarit générique se répète
    | ou sonne artificiel une fois le nom du champ inséré.
    |
    */

    'custom' => [
        'email' => [
            'required' => 'البريد الإلكتروني مطلوب.',
            'email' => 'هذا البريد الإلكتروني غير صالح.',
            'max' => 'يجب ألا يتجاوز البريد الإلكتروني :max حرفا.',
        ],
        'password' => [
            'required' => 'كلمة المرور مطلوبة.',
            'confirmed' => 'كلمتا المرور غير متطابقتين.',
            'min' => 'يجب أن تحتوي كلمة المرور على :min أحرف على الأقل.',
            'max' => 'يجب ألا تتجاوز كلمة المرور :max حرفا.',
        ],
        'locale' => [
            'in' => 'يجب أن تكون اللغة المختارة الفرنسية أو العربية.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Noms lisibles des champs
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'terms_accepted' => 'قبول الشروط العامة للاستخدام',
        'privacy_notice_acknowledged' => 'الاطلاع على سياسة الخصوصية',
        'marketing_granted' => 'الموافقة على التذكيرات',
        'locale' => 'اللغة',
        'token' => 'الرمز',
        'remember' => 'البقاء متصلا',
        'granted' => 'الموافقة',
        'phone' => 'رقم الهاتف',
    ],

];
