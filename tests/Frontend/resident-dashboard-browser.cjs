// Render fixtures with RESIDENT_DASHBOARD_RENDER_DIR during ResidentDashboardTest.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const runBrowser = require('./run-browser.cjs');
const directory = path.resolve(process.argv[2] || 'storage/app/resident-dashboard-check');
const css = ['app', 'resident-booking', 'management-tables'].map(name => fs.readFileSync('public/css/' + name + '.css', 'utf8')).join('\n');
const navigation = fs.readFileSync('public/js/mobile-navigation.js', 'utf8');
const fixtures = ['scheduled', 'empty'].map(name => ({name, html: fs.readFileSync(path.join(directory, name + '.html'), 'utf8')
    .replace(/<link\b[^>]*>/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
    .replace('</head>', '<style>' + css + '</style></head>')
    .replace('</body>', '<script>' + navigation + '</script></body>')}));
const page = path.join(directory, 'browser.html');
fs.writeFileSync(page, `<!doctype html><html><body><pre id="result">RUNNING</pre><script>
const fixtures = ${JSON.stringify(fixtures).replace(/</g, '\\u003c')};
const luminance = color => {
    const values = color.match(/\\d+/g).slice(0, 3).map(value => {
        const channel = Number(value) / 255;
        return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });
    return values[0] * 0.2126 + values[1] * 0.7152 + values[2] * 0.0722;
};
Promise.all(fixtures.flatMap(fixture => [1440, 1024, 768, 390, 320].map(width => new Promise(resolve => {
    const frame = document.createElement('iframe');
    frame.style.width = width + 'px'; frame.style.height = '900px';
    frame.onload = () => { try {
        const doc = frame.contentDocument, win = frame.contentWindow;
        const check = (ok, message) => { if (!ok) throw Error(message); };
        check(doc.documentElement.scrollWidth <= doc.documentElement.clientWidth + 1, 'Horizontal page overflow');
        const cards = [...doc.querySelectorAll('.resident-count')];
        check(cards.length === 2, 'Summary card count');
        const styles = cards.map(card => win.getComputedStyle(card));
        check(styles.every(style => style.backgroundColor === 'rgb(255, 255, 255)'), 'Cards must use the admin white background');
        check(styles[0].color === 'rgb(233, 170, 0)', 'Pending amber accent');
        check(styles[1].color === 'rgb(25, 200, 93)', 'Accepted green accent');
        check(Math.abs(cards[0].getBoundingClientRect().height - cards[1].getBoundingClientRect().height) < 1, 'Unequal card heights');
        check(Math.abs(cards[0].getBoundingClientRect().width - cards[1].getBoundingClientRect().width) < 1, 'Unequal card widths');
        for (const property of ['padding', 'borderRadius', 'fontSize', 'rowGap']) check(styles[0][property] === styles[1][property], 'Mismatched ' + property);
        for (let i = 0; i < cards.length; i++) {
            check(cards[i].classList.contains('stat-card'), 'Admin stat-card component styles not reused');
            check(styles[i].borderLeftWidth === '4px' && styles[i].borderLeftColor === styles[i].color, 'Missing colored left border');
            check(styles[i].borderRadius === '8px' && styles[i].boxShadow !== 'none' && styles[i].padding === '26px', 'Admin card shape or spacing changed');
            check(styles[i].textDecorationLine === 'none', 'Card link is underlined');
            const icon = cards[i].querySelector('svg'), iconStyle = win.getComputedStyle(icon);
            check(icon && icon.getAttribute('aria-hidden') === 'true', 'Missing decorative outline icon');
            check(iconStyle.width === '44px' && iconStyle.height === '44px' && iconStyle.fill === 'none' && iconStyle.stroke === styles[i].color, 'Admin icon style mismatch');
            check(icon.getBoundingClientRect().left >= cards[i].querySelector('div').getBoundingClientRect().right, 'Icon is not right aligned');
            check(win.getComputedStyle(cards[i].querySelector('span')).fontSize === '13px', 'Admin label typography mismatch');
            check(win.getComputedStyle(cards[i].querySelector('strong')).fontSize === '31px', 'Admin value typography mismatch');
            for (const text of cards[i].querySelectorAll('span, strong')) {
                const color = win.getComputedStyle(text).color;
                check((luminance(styles[i].backgroundColor) + 0.05) / (luminance(color) + 0.05) >= 4.5, 'Insufficient text contrast');
            }
            check(new URL(cards[i].href).searchParams.get('status') === (i === 0 ? 'pending' : 'accepted'), 'Summary navigation changed');
        }
        check(win.getComputedStyle(doc.querySelector('.resident-reservation-counts')).gridTemplateColumns.split(' ').length === (width > 520 ? 2 : 1), 'Summary cards do not stack responsively');
        const schedule = doc.querySelector('.resident-upcoming');
        for (const section of doc.querySelectorAll('main, .resident-reservation-counts, .resident-count, .resident-upcoming, .resident-upcoming-item')) {
            check(win.getComputedStyle(section).overflowY === 'visible' && win.getComputedStyle(section).maxHeight === 'none', 'Dashboard has an internal scroll container');
        }
        check(!doc.querySelector('main [data-schedule-list]'), 'All Schedules scroll container leaked into dashboard');
        check(win.getComputedStyle(doc.documentElement).overflowY !== 'hidden' && win.getComputedStyle(doc.body).overflowY !== 'hidden', 'Normal page scrolling disabled');
        check(schedule.querySelector('h2').textContent === 'Your Upcoming Schedule', 'Section title');
        check(!schedule.querySelector('a, button, input, select, summary'), 'Schedule must be information-only');
        check(schedule.scrollWidth <= schedule.clientWidth + 1, 'Schedule overflow');
        if (fixture.name === 'scheduled') {
            check(schedule.querySelectorAll('article').length === 1, 'Expected one reservation');
            const badge = schedule.querySelector('.reservation-status-accepted');
            check(badge && badge.textContent === 'Accepted', 'Accepted badge');
            check(win.getComputedStyle(badge).backgroundColor === 'rgb(220, 252, 231)', 'Existing accepted badge color changed');
        } else {
            check(!schedule.querySelector('article'), 'Unexpected reservation');
            check(schedule.textContent.includes('No upcoming scheduled reservations.'), 'Empty message');
            check(schedule.getBoundingClientRect().height < 220, 'Excessive empty-state space');
        }
        const actions = [...doc.querySelectorAll('.resident-dashboard-actions a')];
        check(actions.length === 2 && actions[0].textContent === 'Browse Facilities' && actions[1].textContent === 'View All Schedules', 'Quick actions');
        check(actions[0].getAttribute('href').endsWith('/facilities') && actions[1].getAttribute('href').endsWith('/calendar'), 'Quick action destinations');
        check(doc.querySelector('.notification-bell'), 'Missing notifications');
        const menu = doc.querySelector('.mobile-menu-button');
        if (win.getComputedStyle(menu).display !== 'none') {
            menu.click();
            const drawer = doc.getElementById('mobile-navigation');
            check(drawer.open && [...drawer.querySelectorAll('a')].some(link => link.textContent.trim() === 'My Reservations'), 'Mobile reservation navigation');
        } else {
            check([...doc.querySelectorAll('.user-nav a')].some(link => link.textContent.trim() === 'My Reservations'), 'Desktop reservation navigation');
        }
        resolve({fixture: fixture.name, width, passed: true});
    } catch (error) { resolve({fixture: fixture.name, width, passed: false, error: error.message}); } };
    frame.srcdoc = fixture.html; document.body.appendChild(frame);
})))).then(results => { document.getElementById('result').textContent = JSON.stringify(results); });
</script></body></html>`);
(async () => {
    const results = await runBrowser(page, path.join(directory, 'chrome-' + process.pid));
    console.log(JSON.stringify(results));
    assert.equal(results.filter(result => !result.passed).length, 0, 'Resident dashboard browser regressions');
    console.log('Passed scheduled and empty dashboards at 1440, 1024, 768, 390, and 320px.');
})().catch(error => { console.error(error); process.exitCode = 1; });
