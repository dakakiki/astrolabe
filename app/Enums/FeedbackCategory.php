<?php

namespace App\Enums;

/** What a piece of feedback from the app is about (Phase 8c). */
enum FeedbackCategory: string
{
    case Bug = 'bug';
    case Idea = 'idea';
    case Question = 'question';
    case Other = 'other';
}
