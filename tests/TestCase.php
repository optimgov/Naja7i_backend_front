<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les migrations créent des types ENUM PostgreSQL (app_locale, user_status,
     * tenant_kind, tenant_status). `migrate:fresh` supprime les tables mais pas
     * les types : sans cette option, la deuxième exécution de la suite échoue
     * sur « type "app_locale" already exists ».
     *
     * RefreshDatabase lit cette propriété pour passer --drop-types.
     */
    protected $dropTypes = true;
}
