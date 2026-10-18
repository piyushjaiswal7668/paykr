<?php

declare(strict_types=1);

use App\Domain\Customers\Services\PasswordService;
use App\Domain\Devices\Services\DeviceSecurity;
use App\Domain\Observations\Data\ObservationData;
use App\Domain\Observations\Enums\ObserverSource;
use App\Domain\Observations\Services\AmountExtractor;
use App\Domain\Observations\Services\BankEmailParser;
use App\Domain\Observations\Services\BankSmsParser;
use App\Domain\Observations\Services\ObservationNormalizer;
use App\Domain\Payments\Services\QrCodeService;
use App\Domain\Payments\Services\UpiProviderRegistry;
use App\Domain\Payments\Services\UpiUri;
use App\Domain\Shared\Errors\ValidationException;
use App\Domain\Shared\ValueObjects\EmailAddress;
use App\Domain\Shared\ValueObjects\MobileNumber;
use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;

it('builds canonical upi://pay URI dynamically with server amount', function () {
    expect(UpiUri::build('store@upi', 'Acme Store', new Money(20000), 'ORDER-99', 'Order payment'))
        ->toBe('upi://pay?pa=store%40upi&pn=Acme+Store&am=200.00&tn=ORDER-99');
    expect(UpiUri::build(' store@upi ', ' A*~& B ', new Money(5), '', ' Note '))
        ->toBe('upi://pay?pa=store%40upi&pn=A*%7E%26+B&am=0.05&tn=Note');
    expect(UpiUri::build('store@upi', 'Acme', new Money(100)))->toBe('upi://pay?pa=store%40upi&pn=Acme&am=1.00');
});

it('strictly excludes cu, tr and all extra parameters even when currency option is passed', function () {
    $uri = UpiUri::build('9876543210@axl', 'Demo Store Merchant', new Money(100), 'ORDER-123');
    parse_str(parse_url($uri, PHP_URL_QUERY), $query);
    expect(array_keys($query))->toBe(['pa', 'pn', 'am', 'tn']);
});

it('builds valid Android intent URI containing only canonical UPI parameters', function () {
    expect(UpiUri::intent(' com.phonepe.app ', 'store@upi', 'Acme', new Money(100), 'ORDER-123'))
        ->toBe('intent://pay?pa=store%40upi&pn=Acme&am=1.00&tn=ORDER-123#Intent;scheme=upi;package=com.phonepe.app;end');
    foreach (['', 'com.app;S.browser_fallback_url=evil', 'not a package'] as $package) {
        expect(fn () => UpiUri::intent($package, 'store@upi', 'Acme', new Money(100)))->toThrow(ValidationException::class);
    }
    expect(fn () => UpiUri::build('store@upi', 'Acme', new Money(0)))->toThrow(ValidationException::class);
});

it('validates 30 official UPI application provider registry', function () {
    $registry = new UpiProviderRegistry;
    expect($registry->all())->toHaveCount(30);
    expect(array_unique(array_column($registry->all(), 'packageName')))->toHaveCount(30);
    expect($registry->findByPackage(' COM.GOOGLE.ANDROID.APPS.NBU.PAISA.USER ')['name'])->toBe('Google Pay');
    expect($registry->findById(' PhonePe ')['packageName'])->toBe('com.phonepe.app');
    expect($registry->findById('paytm')['packageName'])->toBe('net.one97.paytm');
    expect($registry->findById('unknown'))->toBeNull();
    expect($registry->findByPackage(''))->toBeNull();
    foreach ($registry->all() as $provider) {
        expect(file_get_contents(public_path($provider['localIconUrl'])))->toBe($provider['svgLogo']);
        expect($provider['svgLogo'])->toContain('<svg');
    }
});

it('renders real QR SVG at checkout and pairing sizes', function () {
    foreach ([220, 260] as $size) {
        $svg = (new QrCodeService)->svg('upi://pay?pa=store%40upi&am=20.00', $size);
        $xml = simplexml_load_string($svg);
        expect((string) $xml['width'])->toBe((string) $size);
        expect((string) $xml['height'])->toBe((string) $size);
        expect($svg)->toContain('<path')->not->toContain('data:image');
    }
    foreach ([['', 220], ['test', 100], [str_repeat('a', 2001), 220]] as [$text, $size]) {
        expect(fn () => (new QrCodeService)->svg($text, $size))->toThrow(ValidationException::class);
    }
});

it('normalizes customer email and preserves the mobile fallback contract', function () {
    expect((new EmailAddress(' Person@Example.COM '))->value)->toBe('person@example.com');
    expect(EmailAddress::forCustomer(null, new MobileNumber('9876543210'))->value)->toBe('9876543210@customer.paykr.local');
    expect(EmailAddress::forCustomer(' a@b.co ', new MobileNumber('9876543210'))->value)->toBe('a@b.co');
    foreach (['abc', 'a@b.c', 'a b@c.com'] as $email) {
        expect(fn () => new EmailAddress($email))->toThrow(ValidationException::class);
    }
});

it('hashes and verifies passwords securely', function () {
    $service = new PasswordService;
    $hash = $service->hash('CorrectHorseBatteryStaple!42');
    expect($service->verify('CorrectHorseBatteryStaple!42', $hash))->toBeTrue();
    expect($service->verify('WrongPassword', $hash))->toBeFalse();
    expect($service->needsRehash($hash))->toBeFalse();
    $salt = '0123456789abcdef0123456789abcdef';
    $legacy = '100000:'.$salt.':'.hash_pbkdf2('sha512', 'legacy-password', $salt, 100000, 128);
    expect($service->verify('legacy-password', $legacy))->toBeTrue();
    expect($service->verify('wrong-password', $legacy))->toBeFalse();
    expect($service->needsRehash($legacy))->toBeTrue();
    foreach (['garbage', '0:salt:hash', '1000001:'.$salt.':'.str_repeat('a', 128)] as $badHash) {
        expect($service->verify('password', $badHash))->toBeFalse();
    }
    expect(fn () => $service->hash('short'))->toThrow(ValidationException::class);
});

it('matches Node amount extraction and bank parser golden fixtures', function () {
    $cases = json_decode(file_get_contents(base_path('tests/Fixtures/bank-message-parity.json')), true, 512, JSON_THROW_ON_ERROR);
    $at = CarbonImmutable::parse('2026-09-08T10:00:00Z');
    foreach ($cases as $case) {
        $amount = AmountExtractor::extract($case['text']);
        expect($amount['amountStr'] ?? null)->toBe($case['amount']['amountStr'] ?? null);
        expect($amount['amountPaise'] ?? null)->toBe(isset($case['amount']['amountPaise']) ? (int) $case['amount']['amountPaise'] : null);
        foreach (['sms' => BankSmsParser::parse($case['text'], $at), 'email' => BankEmailParser::parse('', $case['text'], $at)] as $channel => $result) {
            if ($case[$channel] === null) {
                expect($result)->toBeNull();

                continue;
            }
            expect($result->amountPaise)->toBe((int) $case[$channel]['amountPaise']);
            expect($result->bankReferenceNumber)->toBe($case[$channel]['bankReferenceNumber'] ?? null);
            expect($result->senderVpa)->toBe($case[$channel]['senderVpa'] ?? null);
            expect($result->observedAt->toIso8601ZuluString())->toBe('2026-09-08T10:00:00Z');
        }
    }
    expect(AmountExtractor::extract('Rs 999999999999999999999999 credited'))->toBeNull();
    expect(BankSmsParser::parse('Rs 2 credited')->observedAt)->toBeInstanceOf(CarbonImmutable::class);
    expect(BankEmailParser::parse('credit Rs 2', '')->rawText)->toBe('credit Rs 2');
});

it('extracts monetary amounts from various notification formats', function () {
    $one = ObservationNormalizer::normalize(new ObservationData('acc_1', ObserverSource::GMAIL, ['snippet' => 'Your account has been credited with Rs. 200.00 on 08-Sep-2026. Ref: UTR12345678']));
    expect($one->amountPaise)->toBe(20000)->and($one->bankReferenceNumber)->toBe('UTR12345678');
    $two = ObservationNormalizer::normalize(new ObservationData('acc_1', ObserverSource::GOOGLE_MESSAGES, ['notificationText' => 'Payment of ₹1,500.50 received from user@upi via PhonePe.']));
    expect($two->amountPaise)->toBe(150050)->and($two->senderVpa)->toBe('user@upi');
});

it('normalizes explicit amount in paise directly', function () {
    $obs = ObservationNormalizer::normalize(new ObservationData('acc_1', ObserverSource::GOOGLE_MESSAGES, ['text' => 'Bank SMS alert'], amountPaise: 49900));
    expect($obs->amountPaise)->toBe(49900);
});

it('intelligently extracts amounts without standard currency symbols e.g. "20.00 received"', function () {
    expect(ObservationNormalizer::normalize(new ObservationData('acc_1', ObserverSource::GMAIL, ['text' => '20.00 received from alt account']))->amountPaise)->toBe(2000);
});

it('validates observation inputs and handles legacy timestamps and explicit overrides', function () {
    foreach ([new ObservationData('', ObserverSource::GMAIL), new ObservationData('acc', ObserverSource::GMAIL, ['text' => []]), new ObservationData('acc', ObserverSource::GMAIL), new ObservationData('acc', ObserverSource::GMAIL, amountPaise: 0)] as $input) {
        expect(fn () => ObservationNormalizer::normalize($input))->toThrow(ValidationException::class);
    }
    $this->freezeTime();
    foreach (['bad date', null, '', 42.5] as $date) {
        $obs = ObservationNormalizer::normalize(new ObservationData('acc', ObserverSource::GMAIL, ['date' => $date], amount: '1.50', bankReferenceNumber: 'REF123', senderVpa: 'me@upi'));
        expect($obs->observedAt->timestamp)->toBe(now()->timestamp);
        expect($obs->amountPaise)->toBe(150)->and($obs->bankReferenceNumber)->toBe('REF123')->and($obs->senderVpa)->toBe('me@upi');
    }
    foreach ([['timestamp' => 1788861600000], ['postTime' => '2026-09-08T10:00:00Z']] as $payload) {
        expect(ObservationNormalizer::normalize(new ObservationData('acc', ObserverSource::GMAIL, $payload, amountPaise: 100))->observedAt->timestamp)->toBe(1788861600);
    }
});

it('generates consistent fingerprints with bank reference numbers', function () {
    $at = CarbonImmutable::parse('2026-09-08T10:00:00Z');
    $one = ObservationNormalizer::fingerprint('acc_1', 20000, ' UTR12345678 ', $at, ObserverSource::GMAIL);
    $two = ObservationNormalizer::fingerprint('acc_1', 20000, 'utr12345678', $at->addMinutes(2), ObserverSource::GOOGLE_MESSAGES);
    expect($one)->toBe($two)->toBe(hash('sha256', 'acc_1:20000:REF:UTR12345678'));
    expect(ObservationNormalizer::fingerprint('acc_2', 20000, 'UTR12345678', $at))->not->toBe($one);
});

it('generates cluster fingerprints within same 60-second window when no reference', function () {
    $at = CarbonImmutable::parse('2026-09-08T10:00:10Z');
    $one = ObservationNormalizer::fingerprint('acc_1', 20000, null, $at);
    expect(ObservationNormalizer::fingerprint('acc_1', 20000, null, $at->addSeconds(35)))->toBe($one);
    expect(ObservationNormalizer::fingerprint('acc_1', 20000, null, $at->addMinutes(5)))->not->toBe($one);
    expect(ObservationNormalizer::fingerprint('acc_1', 20000, null, $at, ObserverSource::GMAIL))->not->toBe(ObservationNormalizer::fingerprint('acc_1', 20000, null, $at, ObserverSource::GOOGLE_MESSAGES));
});

it('verifies real Node P1363 and DER signatures across PEM SPKI and JWK keys', function () {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/device-security-parity.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach ($fixture['signals'] as $case) {
        expect(DeviceSecurity::fingerprint($case['signals']))->toBe($case['hash']);
    }
    foreach ([$fixture['pem'], $fixture['spki'], $fixture['jwk'], json_encode($fixture['jwk'])] as $key) {
        foreach ([$fixture['der'], $fixture['p1363']] as $signature) {
            expect(DeviceSecurity::verifySignature($key, $fixture['payload'], $signature))->toBeTrue();
            expect(DeviceSecurity::verifySignature($key, 'tampered', $signature))->toBeFalse();
        }
        foreach (['', '***', base64_encode(str_repeat("\0", 64))] as $signature) {
            expect(DeviceSecurity::verifySignature($key, $fixture['payload'], $signature))->toBeFalse();
        }
    }
    foreach (['', 'not-a-key', '{bad json}', [], ['kty' => 'RSA'], ['kty' => 'EC', 'crv' => 'P-256', 'x' => '!', 'y' => '!'], ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'YQ', 'y' => 'Yg']] as $key) {
        expect(DeviceSecurity::verifySignature($key, $fixture['payload'], $fixture['der']))->toBeFalse();
    }
});
