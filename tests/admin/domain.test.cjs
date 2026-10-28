const test = require('node:test');
const assert = require('node:assert/strict');
const { boot: bootView, render } = require('./harness.cjs');
const boot = (view, options = {}) => bootView(view, { ...options, widget: false, scripts: ['wizard.js', 'ui-improvements.js'] });
const configuration = { service_provider: 'aws', use_cloudfront: true };
const certificateArn = 'arn:aws:acm:us-east-1:123456789012:certificate/fixture';
const record = { DomainName: 'cdn.example.test', Type: 'CNAME', Name: '_verify.cdn.example.test', Value: '_fixture.acm-validations.aws', systemGenerated: false };

test('custom-domain view seeds safe JSON and unsupported configurations make no requests', async t => {
    const payload = '</script><img src=x onerror=alert(1)>';
    const html = render('domain', { ...configuration, __custom: { domain: payload, certificate_arn: '', records: [record] } });
    assert.doesNotMatch(html, /<\/script><img/);
    assert.match(html, /\\u003C/);
    const seededJson = html.match(/id="initial_certificates">([^<]*)<\/script>/)[1];
    assert.equal(JSON.parse(seededJson).domain, payload);
    const app = await boot('domain', { data: { service_provider: 'google' } });
    t.after(() => app.close());
    assert.equal(app.requests.length, 0);
    assert.equal(app.$('#custom-domain-container').length, 0);
    assert.match(app.$('.notice a').attr('href'), /page=smarl-configuration-wizard/);
});

test('generation failure never enables Attach after elapsed time or schedules fake progress', async t => {
    const app = await boot('domain', { data: configuration });
    t.after(() => app.close());
    app.$('#custom-domain').val('cdn.example.test');
    app.$('#generate-certificate').trigger('click');
    app.latest('generate_ssl_certificate').reject({ message: 'Certificate denied' });
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), true);
    assert.equal(app.$('#generate-certificate').prop('disabled'), false);
    assert.equal(app.timers.size, 0);
    assert.equal(app.$('#domain-message').text(), 'Certificate denied');
});

test('only verified DNS enables Attach; every request has a nonce and records stay text', async t => {
    const app = await boot('domain', { data: configuration });
    t.after(() => app.close());
    app.$('#custom-domain').val('cdn.example.test');
    app.$('#generate-certificate').trigger('click');
    app.latest('generate_ssl_certificate').resolve({
        certificateArn,
        records: [{ ...record, Value: '<img src=x onerror=alert(1)>' }, { ...record, systemGenerated: true }]
    });
    assert.equal(app.$('#records img').length, 0);
    assert.match(app.$('#records').text(), /<img src=x onerror=alert\(1\)>/);
    const firstPoll = app.latest('check_dns_record');
    assert.equal(firstPoll.options.data.nonce, 'fixture-nonce');
    assert.equal(firstPoll.options.data.certificateArn, certificateArn);
    firstPoll.resolve({ verified: false, records: [{ ...record, ValidationStatus: 'PENDING_VALIDATION' }] });
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), true);
    assert.equal(app.timers.size, 1);
    app.runTimer();
    app.latest('check_dns_record').resolve({ verified: true, records: [{ ...record, ValidationStatus: 'SUCCESS' }] });
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), false);
    assert.equal(app.timers.size, 0);
    app.$('#cloudfront-attachment-button').trigger('click');
    assert.equal(app.latest('attach_to_cloudfront').options.data.nonce, 'fixture-nonce');
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), true);
    app.latest('attach_to_cloudfront').resolve();
    assert.equal(app.$('.wizard-progress-bar-inner')[0].style.width, '100%');
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), true);
});

test('editing the domain cancels polling and ignores stale DNS or generation responses', async t => {
    const app = await boot('domain', { data: {
        ...configuration, __custom: { certificate_arn: certificateArn, domain: 'cdn.example.test', records: [record] }
    } });
    t.after(() => app.close());
    const stale = app.latest('check_dns_record');
    app.$('#custom-domain').val('other.example.test').trigger('input');
    stale.resolve({ verified: true, records: [{ ...record, ValidationStatus: 'SUCCESS' }] });
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), true);
    assert.equal(app.timers.size, 0);
    app.$('#generate-certificate').trigger('click');
    const staleGeneration = app.latest('generate_ssl_certificate');
    app.$('#custom-domain').val('new.example.test').trigger('input');
    staleGeneration.resolve({ certificateArn, records: [record] });
    assert.equal(app.$('#records').text(), '');
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), true);
});

test('validation records published asynchronously by ACM appear on subsequent polling', async t => {
    const app = await boot('domain', { data: configuration });
    t.after(() => app.close());
    app.$('#custom-domain').val('cdn.example.test');
    app.$('#generate-certificate').trigger('click');
    const synthetic = { DomainName: 'cdn.example.test', Type: 'CNAME', Name: 'cdn.example.test', Value: 'fixture.cloudfront.net', systemGenerated: true };
    app.latest('generate_ssl_certificate').resolve({ certificateArn, records: [synthetic] });
    assert.doesNotMatch(app.$('#records').text(), /_verify/);
    app.latest('check_dns_record').resolve({ verified: false, records: [{ ...record, ValidationStatus: 'PENDING_VALIDATION' }, { ...synthetic, ValidationStatus: 'PENDING_VALIDATION' }] });
    assert.match(app.$('#records').text(), /_verify.cdn.example.test/);
    assert.equal(app.$('#records .dns-status').length, 2);
    assert.equal(app.$('#cloudfront-attachment-button').prop('disabled'), true);
});

test('DNS transport failure offers a nonce-bearing retry without duplicate timers', async t => {
    const app = await boot('domain', { data: {
        ...configuration, __custom: { certificate_arn: certificateArn, domain: 'cdn.example.test', records: [record] }
    } });
    t.after(() => app.close());
    app.latest('check_dns_record').fail({ message: '<img src=x onerror=alert(1)>retry' });
    assert.equal(app.$('#domain-message img').length, 0);
    assert.equal(app.$('#check-dns-records').prop('hidden'), false);
    app.$('#check-dns-records').trigger('click');
    app.latest('check_dns_record').resolve({ verified: false, records: [] });
    assert.equal(app.timers.size, 1);
    assert.equal(app.requests.filter(request => request.action === 'check_dns_record').length, 2);
    assert.ok(app.requests.every(request => request.options.data.nonce === 'fixture-nonce'));
});

test('domain inputs reject schemes and paths before issuing requests', async t => {
    const app = await boot('domain', { data: configuration });
    t.after(() => app.close());
    for (const input of ['https://cdn.example.test', 'cdn.example.test/path', '<img src=x>', '127.0.0.1']) {
        app.$('#custom-domain').val(input);
        app.$('#generate-certificate').trigger('click');
    }
    assert.equal(app.requests.length, 0);
});
