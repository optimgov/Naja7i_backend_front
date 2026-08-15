<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Resources\Coupons\CouponResource;
use App\Models\Coupon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    /**
     * LE CODE ET L'AUTEUR SONT POSÉS ICI, jamais saisis.
     *
     * Le code est tiré par `Coupon::engendrer()` — alphabet sans caractère
     * ambigu, ~57 bits d'entropie. Laisser le saisir produirait
     * « PROMO2026 », que n'importe qui devine.
     *
     * `created_by` est l'utilisateur connecté et rien d'autre : sur un titre
     * qui vaut de l'argent, savoir qui l'a émis n'est pas une commodité, c'est
     * la piste d'audit.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['code'] = Coupon::engendrer();
        $data['created_by'] = auth()->id();
        $data['used_count'] = 0;
        $data['status'] = 'actif';

        return Coupon::create($data);
    }
}
