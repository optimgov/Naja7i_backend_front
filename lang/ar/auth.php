<?php

return [
    'invalid_credentials' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
    'email_already_used' => 'يوجد حساب مسجل بهذا البريد الإلكتروني.',
    'account_suspended' => 'هذا الحساب موقوف. يرجى الاتصال بالدعم.',
    'unauthenticated' => 'يجب تسجيل الدخول للوصول إلى هذا المورد.',
    'throttled' => 'محاولات كثيرة جدا. أعد المحاولة بعد :seconds ثانية.',
    'terms_required' => 'يجب قبول الشروط العامة لإنشاء حساب.',
    'privacy_required' => 'يجب تأكيد اطلاعك على سياسة الخصوصية.',
    'legal_not_revocable' => 'لا يمكن سحب الشروط العامة أو سياسة الخصوصية من هنا. للتوقف عن استخدام الخدمة، اطلب حذف حسابك.',
    'email_not_verified' => 'أكد بريدك الإلكتروني للمتابعة. تم إرسال رابط إليك.',

    // --- PAS-3 : vérification d'e-mail et mot de passe oublié ---
    'verification_token_invalid' => 'رابط التأكيد لم يعد صالحا. اطلب رابطا جديدا.',
    'verification_link_sent' => 'إذا كان هذا البريد مرتبطا بحساب غير مؤكد، فقد تم إرسال رابط.',
    'reset_link_sent' => 'إذا كان هذا البريد مرتبطا بحساب، فقد تم إرسال رابط إعادة التعيين.',
    'reset_token_invalid' => 'رابط إعادة التعيين لم يعد صالحا. اطلب رابطا جديدا.',
    'password_updated' => 'تم تحديث كلمة المرور. يمكنك الآن تسجيل الدخول.',
    'current_password_invalid' => 'كلمة المرور الحالية غير صحيحة.',
    'password_identity_required' => 'هذا الحساب لا يملك بعد هوية بكلمة مرور.',
    'invitation_invalid' => 'هذه الدعوة لم تعد صالحة.',
    'invitation_accepted' => 'تم قبول دعوتك. يمكنك الآن تسجيل الدخول.',

    // PAS-11 — refus d'autorisation fine (ADR-0009).
    'permission_denied' => 'ليست لديك الصلاحية اللازمة للقيام بهذا الإجراء.',
];
