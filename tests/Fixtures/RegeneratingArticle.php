<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Vortech\Localize\Attributes\LocalizedSlug;

#[LocalizedSlug(from: 'title', regenerate: true)]
class RegeneratingArticle extends Article
{
    protected $table = 'articles';

    public function getTranslationForeignKey(): string
    {
        return 'article_id';
    }
}
