import { chromium } from 'playwright';
import dns from 'node:dns/promises';
import net from 'node:net';

const PROTOCOL_VERSION = 1;
const MAX_OBSERVATIONS = 2000;
const MAX_ERRORS = 200;
const MAX_TRACKING_REQUESTS = 1000;

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', chunk => { data += chunk; });
    process.stdin.on('end', () => {
      try { resolve(JSON.parse(data)); } catch { reject(new Error('Runner received invalid JSON.')); }
    });
    process.stdin.on('error', reject);
  });
}

function safeMessage(value) {
  let message = String(value ?? '');
  message = message.replace(/(authorization|cookie|token|password|passwd|secret|api[_-]?key|jwt|session)\s*[:=]\s*[^\s,;]+/gi, '$1=[REDACTED]');
  return message.slice(0, 1000);
}

function redactUrl(rawUrl, redactAllQueryValues = true) {
  try {
    const url = new URL(rawUrl);
    for (const key of [...url.searchParams.keys()]) {
      if (redactAllQueryValues || /^(token|auth|password|passwd|secret|key|apikey|api_key|jwt|session|sessionid|email|code|access_token|refresh_token)$/i.test(key)) {
        url.searchParams.set(key, '[REDACTED]');
      }
    }
    url.hash = '';
    return url.toString();
  } catch {
    return '[invalid-url]';
  }
}

function ipv4ToInt(ip) { return ip.split('.').reduce((acc, octet) => ((acc << 8) | Number(octet)) >>> 0, 0) >>> 0; }
function ipv4InRange(ip, base, prefix) {
  const value = ipv4ToInt(ip);
  const baseValue = ipv4ToInt(base);
  const mask = prefix === 0 ? 0 : (0xffffffff << (32 - prefix)) >>> 0;
  return (value & mask) === (baseValue & mask);
}
function isPrivateOrReservedIp(ip) {
  const version = net.isIP(ip);
  if (version === 4) {
    return [['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],['172.16.0.0',12],['192.0.0.0',24],['192.0.2.0',24],['192.168.0.0',16],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]].some(([base,prefix]) => ipv4InRange(ip, base, prefix));
  }
  if (version === 6) {
    const normalized = ip.toLowerCase();
    if (normalized.startsWith('::ffff:')) return isPrivateOrReservedIp(normalized.slice(7));
    return normalized === '::' || normalized === '::1' || normalized.startsWith('fc') || normalized.startsWith('fd') || /^fe[89ab]/.test(normalized) || normalized.startsWith('ff') || normalized.startsWith('2001:db8:');
  }
  return true;
}

async function assertPublicHostname(hostname, allowPrivateNetworkTargets) {
  const host = hostname.toLowerCase().replace(/\.$/, '');
  if (allowPrivateNetworkTargets) return;
  if (host === 'localhost' || host.endsWith('.localhost')) throw new Error('Blocked loopback hostname.');
  if (net.isIP(host)) {
    if (isPrivateOrReservedIp(host)) throw new Error('Blocked private or reserved network address.');
    return;
  }
  let addresses;
  try { addresses = await dns.lookup(host, { all: true, verbatim: true }); } catch { throw new Error('Hostname resolution failed.'); }
  if (!addresses.length) throw new Error('Hostname resolution returned no addresses.');
  for (const { address } of addresses) if (isPrivateOrReservedIp(address)) throw new Error('Hostname resolved to a private or reserved network address.');
}

function hostMatches(host, pattern) {
  const value = String(host).toLowerCase().replace(/\.$/, '');
  const allowed = String(pattern).toLowerCase().replace(/\.$/, '');
  if (value === allowed) return true;
  if (allowed.startsWith('*.')) {
    const suffix = allowed.slice(1);
    return value.endsWith(suffix) && value !== suffix.slice(1);
  }
  return false;
}
function assertAllowedNavigationHost(host, allowedHosts) {
  if (!allowedHosts.some(pattern => hostMatches(host, pattern))) throw new Error('Navigation redirected to a host that is not approved for this diagnostic.');
}

function parseTrackingParameters(request) {
  let url;
  try { url = new URL(request.url()); } catch { return null; }
  const params = new URLSearchParams(url.search);
  const postData = request.postData();
  if (postData && postData.length <= 1024 * 1024) {
    try {
      const bodyParams = new URLSearchParams(postData);
      for (const [key, value] of bodyParams.entries()) if (!params.has(key)) params.set(key, value);
    } catch { }
  }
  const hasRecorderFlag = params.get('rec') === '1';
  const hasSiteId = params.has('idsite');
  const hasTrackingShape = params.has('action_name') || params.has('e_c') || params.has('e_a') || params.has('idgoal') || params.has('ec_id') || params.has('revenue') || params.has('download') || params.has('link');
  if (!(hasRecorderFlag && (hasSiteId || hasTrackingShape))) return null;
  let type = 'pageview';
  if (params.has('e_c') || params.has('e_a')) type = 'event';
  if (params.has('idgoal')) type = 'goal';
  if (params.has('ec_id')) type = 'ecommerce_order';
  if (params.has('download')) type = 'download';
  if (params.has('link')) type = 'outlink';
  return {
    type,
    siteId: params.get('idsite') ?? null,
    actionName: params.get('action_name') ?? null,
    category: params.get('e_c') ?? null,
    action: params.get('e_a') ?? null,
    name: params.get('e_n') ?? null,
    goalId: params.get('idgoal') ?? null,
    orderId: params.get('ec_id') ?? null,
    revenue: params.get('revenue') ?? null,
    method: request.method(),
    endpoint: `${url.origin}${url.pathname}`,
    responseStatus: null,
    timestamp: new Date().toISOString(),
  };
}

function locatorFor(page, locator) {
  if (!locator || typeof locator !== 'object') throw new Error('Scenario step requires a locator.');
  switch (locator.type) {
    case 'role': return page.getByRole(locator.role, locator.name ? { name: locator.name } : undefined);
    case 'label': return page.getByLabel(locator.value);
    case 'placeholder': return page.getByPlaceholder(locator.value);
    case 'text': return page.getByText(locator.value, { exact: Boolean(locator.exact) });
    case 'testId': return page.getByTestId(locator.value);
    case 'css': return page.locator(locator.value);
    default: throw new Error(`Unsupported locator type: ${String(locator.type)}`);
  }
}
function generatedValue(source, runId) {
  switch (source) {
    case 'generated-email': return `tracking-assistant+${runId}@example.invalid`;
    case 'generated-text': return `tracking-assistant-${runId}`;
    default: throw new Error(`Unsupported valueSource: ${String(source)}`);
  }
}

function previewBootstrapUrl(target, preview) {
  const url = new URL(target.toString());
  if (preview?.containerId) {
    url.searchParams.set('mtmPreviewMode', String(preview.containerId));
    url.searchParams.set('mtmSetDebugFlag', '1');
  }
  return url.toString();
}

async function installClickEvidence(context, job, observations) {
  const selectors = Array.isArray(job.inspection?.cssSelectors) ? job.inspection.cssSelectors.slice(0, 100) : [];
  await context.exposeBinding('__trackingAssistantCaptureClick', (_source, payload) => {
    if (!payload || typeof payload !== 'object' || observations.length >= MAX_OBSERVATIONS) return;
    observations.push({ type: 'click_element', ...payload, timestamp: new Date().toISOString() });
  });

  await context.addInitScript(({ inspections }) => {
    const cssEscape = value => {
      if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
      return String(value).replace(/[^a-zA-Z0-9_-]/g, ch => `\\${ch}`);
    };
    const safeQuery = selector => {
      try { return Array.from(document.querySelectorAll(selector)); } catch { return []; }
    };
    const candidateBaseSelectors = element => {
      const candidates = [];
      const attrs = [['data-testid',100],['data-test',98],['data-action',96],['data-track',94]];
      for (const [name, score] of attrs) {
        const value = element.getAttribute(name);
        if (value && value.length <= 200) candidates.push({ selector: `[${name}="${String(value).replace(/\\/g,'\\\\').replace(/"/g,'\\"')}"]`, score });
      }
      if (element.id && element.id.length <= 128) candidates.push({ selector: `#${cssEscape(element.id)}`, score: 90 });
      const classes = Array.from(element.classList || []).filter(v => v && v.length <= 80).slice(0, 3);
      if (classes.length) {
        const classSelector = classes.map(v => `.${cssEscape(v)}`).join('');
        candidates.push({ selector: `${element.tagName.toLowerCase()}${classSelector}`, score: 76 });
        candidates.push({ selector: classSelector, score: 72 });
      }
      return candidates;
    };

    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const selectorMatches = {};
      for (const inspection of inspections) {
        if (!inspection || typeof inspection.key !== 'string' || typeof inspection.selector !== 'string') continue;
        const nodes = safeQuery(inspection.selector);
        selectorMatches[inspection.key] = nodes.includes(target);
      }

      const rawCandidates = [];
      let node = target;
      for (let depth = 0; node && depth < 4; depth += 1, node = node.parentElement) {
        for (const candidate of candidateBaseSelectors(node)) {
          const selector = depth === 0 ? candidate.selector : `${candidate.selector}, ${candidate.selector} *`;
          const nodes = safeQuery(selector);
          rawCandidates.push({
            selector,
            score: candidate.score - (depth * 8),
            matchesTarget: nodes.includes(target),
            documentCount: nodes.length,
          });
        }
      }
      const seen = new Set();
      const selectorCandidates = rawCandidates.filter(candidate => {
        if (seen.has(candidate.selector)) return false;
        seen.add(candidate.selector);
        return candidate.selector.length <= 1000;
      }).slice(0, 20);

      const safeAttributes = {};
      for (const name of ['data-testid','data-test','data-action','data-track']) {
        const value = target.getAttribute(name);
        if (value && value.length <= 200) safeAttributes[name] = value;
      }
      const payload = {
        target: {
          tagName: target.tagName.toLowerCase(),
          id: target.id ? String(target.id).slice(0,128) : null,
          classes: Array.from(target.classList || []).slice(0,8).map(v => String(v).slice(0,80)),
          attributes: safeAttributes,
        },
        selectorMatches,
        selectorCandidates,
      };
      try { void window.__trackingAssistantCaptureClick(payload); } catch { }
    }, true);
  }, { inspections: selectors });
}

async function executeScenario(page, job, observations) {
  const steps = job.scenario?.steps ?? [];
  const stepTimeout = Math.min(15000, Math.max(1000, Math.floor(job.timeoutMs / Math.max(steps.length || 1, 1))));
  for (let index = 0; index < steps.length; index += 1) {
    const step = steps[index];
    const action = step.action;
    const observation = { type: 'scenario_step', index, action, ok: false, timestamp: new Date().toISOString() };
    if (step.locator) observation.locator = { type: step.locator.type, role: step.locator.role ?? null, value: step.locator.value ?? null, name: step.locator.name ?? null };
    try {
      switch (action) {
        case 'navigate': {
          const url = new URL(step.url, page.url()).toString();
          await page.goto(url, { waitUntil: 'domcontentloaded', timeout: stepTimeout });
          break;
        }
        case 'click':
          await locatorFor(page, step.locator).click({ timeout: stepTimeout });
          await page.waitForTimeout(50);
          break;
        case 'fill': {
          const value = step.valueSource ? generatedValue(step.valueSource, job.runId) : String(step.value ?? '');
          await locatorFor(page, step.locator).fill(value, { timeout: stepTimeout });
          break;
        }
        case 'select': await locatorFor(page, step.locator).selectOption(step.value, { timeout: stepTimeout }); break;
        case 'check': await locatorFor(page, step.locator).check({ timeout: stepTimeout }); break;
        case 'uncheck': await locatorFor(page, step.locator).uncheck({ timeout: stepTimeout }); break;
        case 'press': await locatorFor(page, step.locator).press(String(step.key ?? step.value ?? 'Enter'), { timeout: stepTimeout }); break;
        case 'submit':
          await locatorFor(page, step.locator).evaluate(element => {
            if (element instanceof HTMLFormElement) { element.requestSubmit(); return; }
            const form = element.closest('form');
            if (!form) throw new Error('Submit locator is not inside a form.');
            form.requestSubmit();
          });
          break;
        case 'waitForElement': await locatorFor(page, step.locator).waitFor({ state: 'visible', timeout: stepTimeout }); break;
        case 'waitForText': await locatorFor(page, step.locator).waitFor({ state: 'visible', timeout: stepTimeout }); break;
        case 'waitForUrl': await page.waitForURL(step.url ?? step.value, { timeout: stepTimeout }); break;
        case 'waitForNetworkIdle': await page.waitForLoadState('networkidle', { timeout: stepTimeout }); break;
        default: throw new Error(`Unsupported scenario action: ${String(action)}`);
      }
      observation.ok = true;
      if (observations.length < MAX_OBSERVATIONS) observations.push(observation);
    } catch (error) {
      observation.error = safeMessage(error.message);
      if (observations.length < MAX_OBSERVATIONS) observations.push(observation);
      throw error;
    }
  }
}

function validateJob(job) {
  if (!job || job.protocolVersion !== PROTOCOL_VERSION) throw new Error('Unsupported runner protocol version.');
  if (!Number.isInteger(job.runId) || job.runId <= 0) throw new Error('Invalid runId.');
  if (job.browser !== 'chromium') throw new Error('Tracking Assistant currently supports Chromium only.');
  const target = new URL(job.target?.url);
  if (!['http:', 'https:'].includes(target.protocol)) throw new Error('Only HTTP(S) targets are allowed.');
  if (target.username || target.password) throw new Error('Embedded URL credentials are not allowed.');
  if (!Array.isArray(job.scenario?.steps)) throw new Error('scenario.steps must be an array.');
  if (job.scenario.steps.length > 50) throw new Error('Scenario exceeds the 50-step limit.');
  return target;
}

async function run(job) {
  const target = validateJob(job);
  const observations = [];
  const trackingRequests = [];
  const errors = [];
  const trackingByRequest = new Map();
  const matomoDetection = { matomoJsLoaded: false, mtmContainerLoaded: false, directQueuePresent: false, mtmQueuePresent: false, mtmContainerIds: [] };
  const allowedHosts = job.networkPolicy?.allowedNavigationHosts ?? [target.hostname];
  const allowPrivate = Boolean(job.networkPolicy?.allowPrivateNetworkTargets);
  assertAllowedNavigationHost(target.hostname, allowedHosts);
  await assertPublicHostname(target.hostname, allowPrivate);

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ acceptDownloads: false, serviceWorkers: 'block', permissions: [] });
  await installClickEvidence(context, job, observations);
  const deadline = Date.now() + Math.max(10000, Number(job.timeoutMs) || 60000);
  const page = await context.newPage();
  page.setDefaultTimeout(Math.min(15000, Math.max(1000, Number(job.timeoutMs) || 60000)));

  await context.route('**/*', async route => {
    const request = route.request();
    try {
      const url = new URL(request.url());
      if (!['http:', 'https:'].includes(url.protocol)) { await route.abort('blockedbyclient'); return; }
      await assertPublicHostname(url.hostname, allowPrivate);
      if (request.isNavigationRequest()) assertAllowedNavigationHost(url.hostname, allowedHosts);
      await route.continue();
    } catch (error) {
      if (errors.length < MAX_ERRORS) errors.push({ type: 'network_policy', message: safeMessage(error.message), url: redactUrl(request.url(), true) });
      await route.abort('blockedbyclient');
    }
  });

  page.on('request', request => {
    if (observations.length < MAX_OBSERVATIONS) observations.push({ type: 'network_request', method: request.method(), resourceType: request.resourceType(), url: redactUrl(request.url(), Boolean(job.privacy?.redactQueryValues)), timestamp: new Date().toISOString() });
    const url = request.url();
    if (/\/(matomo|piwik)\.js(?:[?#]|$)/i.test(url)) matomoDetection.matomoJsLoaded = true;
    const containerMatch = url.match(/\/container_([^/?#]+)\.js(?:[?#]|$)/i);
    if (containerMatch) {
      matomoDetection.mtmContainerLoaded = true;
      if (!matomoDetection.mtmContainerIds.includes(containerMatch[1])) matomoDetection.mtmContainerIds.push(containerMatch[1]);
    }
    if (trackingRequests.length < MAX_TRACKING_REQUESTS) {
      const parsed = parseTrackingParameters(request);
      if (parsed) { trackingRequests.push(parsed); trackingByRequest.set(request, parsed); }
    }
  });
  page.on('response', response => {
    const tracking = trackingByRequest.get(response.request());
    if (tracking) tracking.responseStatus = response.status();
    if (observations.length < MAX_OBSERVATIONS) observations.push({ type: 'network_response', status: response.status(), url: redactUrl(response.url(), Boolean(job.privacy?.redactQueryValues)), timestamp: new Date().toISOString() });
  });
  page.on('console', message => {
    if (!['error','warning'].includes(message.type())) return;
    if (observations.length < MAX_OBSERVATIONS) observations.push({ type: 'console', level: message.type(), message: safeMessage(message.text()), timestamp: new Date().toISOString() });
  });
  page.on('pageerror', error => { if (errors.length < MAX_ERRORS) errors.push({ type: 'page_exception', message: safeMessage(error.message) }); });
  page.on('requestfailed', request => { if (errors.length < MAX_ERRORS) errors.push({ type: 'request_failed', message: safeMessage(request.failure()?.errorText ?? 'Request failed'), url: redactUrl(request.url(), true) }); });
  page.on('framenavigated', frame => { if (frame === page.mainFrame() && observations.length < MAX_OBSERVATIONS) observations.push({ type: 'navigation', url: redactUrl(frame.url(), true), timestamp: new Date().toISOString() }); });

  try {
    await page.goto(previewBootstrapUrl(target, job.preview), { waitUntil: 'domcontentloaded', timeout: Math.max(1000, deadline - Date.now()) });
    await executeScenario(page, job, observations);
    await page.waitForTimeout(500);
    const queueState = await page.evaluate(() => ({ paq: Array.isArray(window._paq), mtm: Array.isArray(window._mtm), mtmLength: Array.isArray(window._mtm) ? window._mtm.length : 0 }));
    matomoDetection.directQueuePresent = Boolean(queueState.paq);
    matomoDetection.mtmQueuePresent = Boolean(queueState.mtm);
    matomoDetection.mtmQueueLength = Number(queueState.mtmLength) || 0;
    return {
      protocolVersion: PROTOCOL_VERSION,
      runId: job.runId,
      status: 'completed',
      observations,
      trackingRequests,
      errors,
      matomoDetection,
      scenarioResult: { completedSteps: job.scenario.steps.length, finalUrl: redactUrl(page.url(), true), preview: Boolean(job.preview?.containerId) },
    };
  } finally {
    await context.close();
    await browser.close();
  }
}

let job;
try {
  job = await readStdin();
  const result = await run(job);
  process.stdout.write(JSON.stringify(result));
} catch (error) {
  process.stdout.write(JSON.stringify({ protocolVersion: PROTOCOL_VERSION, runId: Number(job?.runId) || 0, status: 'failed', observations: [], trackingRequests: [], errors: [{ type: 'runner', message: safeMessage(error.message) }], scenarioResult: {} }));
}
