<?php

namespace App\Enums;

enum QuestionPreparationBatchStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case INTERRUPTED = 'interrupted';
}
