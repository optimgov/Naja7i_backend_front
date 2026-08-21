<?php

namespace App\Enums;

enum QuestionPreparationEventType: string
{
    case ASSIGNMENT_CHANGED = 'assignment_changed';
    case QUALIFICATION_CHANGED = 'qualification_changed';
    case DIFFICULTY_CHANGED = 'difficulty_changed';
    case ANSWER_CONFIRMED = 'answer_confirmed';
    case MARKED_DUPLICATE = 'marked_duplicate';
    case MARKED_ILLEGIBLE = 'marked_illegible';
    case REJECTED = 'rejected';
}
