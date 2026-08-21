<?php

namespace App\Enums;

enum PreparedQuestionState: string
{
    case IMPORTED = 'imported';
    case QUALIFIED = 'qualified';
    case ANSWERED = 'answered';
    case TRANSFERRED = 'transferred';
    case ILLEGIBLE = 'illegible';
    case DUPLICATE = 'duplicate';
    case REJECTED = 'rejected';
    case REPLACED = 'replaced';

    /**
     * Le statut du corpus reste un fait de source, mais ne crée jamais une
     * validation parallèle. `saisi` et `valide` reviennent donc dans la file :
     * aucune réponse anonyme n'est promue en réponse confirmée.
     */
    public static function fromSourceStatus(?string $status): self
    {
        return match ($status) {
            'source_illisible' => self::ILLEGIBLE,
            'a_saisir', 'saisi', 'valide', null, '' => self::IMPORTED,
            default => self::IMPORTED,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::TRANSFERRED,
            self::ILLEGIBLE,
            self::DUPLICATE,
            self::REJECTED,
            self::REPLACED,
        ], true);
    }
}
