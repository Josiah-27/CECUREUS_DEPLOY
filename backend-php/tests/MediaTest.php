<?php

declare(strict_types=1);

use Cecureus\Support\Media;

test('https image urls pass through untouched, other schemes are dropped', function (): void {
    check(Media::url('https://cdn.example/d.png') === 'https://cdn.example/d.png', 'https url changed');
    check(Media::url('HTTPS://cdn.example/d.png') === 'HTTPS://cdn.example/d.png', 'uppercase https url changed');
    check(Media::url('http://cdn.example/d.png') === null, 'http url kept');
    check(Media::url('data:image/png;base64,AAAA') === null, 'data url kept');
    check(Media::url('javascript:alert(1)') === null, 'javascript url kept');
    check(Media::url('  https://cdn.example/d.png ') === 'https://cdn.example/d.png', 'whitespace not trimmed');
});

test('bare file names join the configured base and url-encode each segment', function (): void {
    putenv('MEDIA_DOCTOR_BASE_URL=https://cdn.example/uploads/doctor');
    try {
        check(Media::url('1633080520photo.png', 'MEDIA_DOCTOR_BASE_URL') === 'https://cdn.example/uploads/doctor/1633080520photo.png', 'file name not joined');
        check(Media::url('a b/Dr Photo.png', 'MEDIA_DOCTOR_BASE_URL') === 'https://cdn.example/uploads/doctor/a%20b/Dr%20Photo.png', 'segments not encoded');
        check(Media::url('/leading/slash.png', 'MEDIA_DOCTOR_BASE_URL') === 'https://cdn.example/uploads/doctor/leading/slash.png', 'double slash after base');
    } finally {
        putenv('MEDIA_DOCTOR_BASE_URL');
    }
});

test('an empty specific base falls back to MEDIA_BASE_URL', function (): void {
    putenv('MEDIA_BASE_URL=https://base.example/uploads');
    putenv('MEDIA_DOCTOR_BASE_URL=');
    try {
        check(Media::url('photo.png', 'MEDIA_DOCTOR_BASE_URL') === 'https://base.example/uploads/photo.png', 'fallback base not used');
    } finally {
        putenv('MEDIA_DOCTOR_BASE_URL');
        putenv('MEDIA_BASE_URL');
    }
});

test('empty column values stay null', function (): void {
    check(Media::url(null) === null, 'null not null');
    check(Media::url('') === null, 'empty not null');
    check(Media::url('   ') === null, 'blank not null');
});
