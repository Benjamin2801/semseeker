<?php

namespace App\Suggest\Source;

final class YouTubeSource extends AbstractGoogleSuggestSource
{
    public function getName(): string
    {
        return 'youtube';
    }

    public function getAcronym(): string
    {
        return 'YTB';
    }

    protected function getDataset(): string
    {
        return 'yt';
    }
}
