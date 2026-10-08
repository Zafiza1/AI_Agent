<?php

namespace App\Enums;

enum RepositoryProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Other = 'other';

    public static function fromUrl(string $url): self
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_ends_with($host, 'github.com') => self::GitHub,
            str_ends_with($host, 'gitlab.com') => self::GitLab,
            str_ends_with($host, 'bitbucket.org') => self::Bitbucket,
            default => self::Other,
        };
    }
}
