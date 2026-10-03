/**
 * Glass Government Legistar Worker
 * Version: 2.0.0
 * - JSON first, XML fallback
 * - One event shape for every client (see normalizeEvent)
 * - Upstream failures return 502 and are never cached
 * - 30-day lookback + future events, 15 min Cloudflare cache
 * - ?debug=1 only honoured with a matching X-Debug-Token (env.DEBUG_TOKEN)
 */

const TIMEZONE = "America/Chicago";

// Path segment -> Legistar API client slug and public site host.
// NOTE: the "dane" slug is taken from the README / scraper host; confirm it
// against https://webapi.legistar.com/v1/dane/events?$top=1
const CLIENTS = {
  madison: { slug: "madison", site: "madison.legistar.com" },
  dane: { slug: "dane", site: "dane.legistar.com" }
};

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    const m = url.pathname.match(/^\/events\/([a-z]+)$/);
    const client = m && CLIENTS[m[1]] ? m[1] : null;
    if (!client) return new Response("Not found", { status: 404 });
    const { slug, site } = CLIENTS[client];

    const debug =
      url.searchParams.get("debug") === "1" &&
      !!env.DEBUG_TOKEN &&
      request.headers.get("X-Debug-Token") === env.DEBUG_TOKEN;

    const cacheKey = new Request(url.origin + url.pathname);
    const cache = caches.default;

    if (!debug) {
      const cached = await cache.match(cacheKey);
      if (cached) return cached;
    }

    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - 30);
    const cutoffDateTime = cutoff.toISOString().split(".")[0]; // YYYY-MM-DDTHH:MM:SS
    const legistarURL = `https://webapi.legistar.com/v1/${slug}/events?$orderby=EventDate&$filter=EventDate ge datetime'${cutoffDateTime}'`;

    let events;
    try {
      const res = await fetch(legistarURL, {
        headers: {
          "Accept": "application/json",
          "User-Agent": "GlassGovernment/legistar-cache"
        }
      });
      const rawText = await res.text();

      if (debug) {
        return json({ source: client, status: res.status, raw_response: rawText, url: legistarURL }, 200, "no-store");
      }

      if (!res.ok) {
        return json({ source: client, error: `Legistar returned HTTP ${res.status}`, events: [] }, 502, "no-store");
      }

      events = parseEvents(rawText, site);
      if (events === null) {
        return json({ source: client, error: "Unrecognised Legistar response", events: [] }, 502, "no-store");
      }
    } catch (err) {
      return json({ source: client, error: "Legistar fetch failed", events: [] }, 502, "no-store");
    }

    events = events.filter(e => isWithinLast30DaysOrFuture(e.datetime));

    const response = json({
      source: client,
      timezone: TIMEZONE,
      fetched_count: events.length,
      count: events.length,
      generated: new Date().toISOString(),
      events
    }, 200, "public, max-age=900");

    ctx.waitUntil(cache.put(cacheKey, response.clone()));
    return response;
  }
};

function json(obj, status, cacheControl) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { "Content-Type": "application/json", "Cache-Control": cacheControl }
  });
}

/* ---------------------------------------------------------
 * Parsing / normalizing
 * --------------------------------------------------------- */

// Returns an array of normalized events, or null if the body is not a
// recognisable Legistar events response.
function parseEvents(rawText, site) {
  let data = null;
  try { data = JSON.parse(rawText); } catch { /* fall through to XML */ }

  const list = Array.isArray(data) ? data : data && Array.isArray(data.value) ? data.value : null;
  if (list) return list.map(e => normalizeEvent(e, site)).filter(Boolean);

  if (rawText.includes("<GranicusEvent>")) return normalizeXML(rawText, site);
  return null;
}

/**
 * Shared event shape:
 *   event_id, guid, last_modified, title, datetime (local wall-clock,
 *   YYYY-MM-DDTHH:MM:SS, no offset; zone is the top-level `timezone`),
 *   location, source_url, agenda_file, minutes_file, video, status, comment
 */
function normalizeEvent(e, site) {
  const datetime = parseLegistarDate(e.EventDate, e.EventTime);
  if (!datetime || e.EventId == null) return null;

  return {
    event_id: String(e.EventId),
    guid: e.EventGuid || "",
    last_modified: e.EventLastModifiedUtc || "",
    title: e.EventBodyName || "Meeting",
    datetime,
    location: e.EventLocation || "",
    source_url: e.EventInSiteURL || `https://${site}/MeetingDetail.aspx?ID=${e.EventId}`,
    agenda_file: e.EventAgendaFile || "",
    minutes_file: e.EventMinutesFile || "",
    video: e.EventVideoPath || e.EventMedia || "",
    status: e.EventAgendaStatusName || "",
    comment: e.EventComment || ""
  };
}

function normalizeXML(xmlText, site) {
  const events = [];
  for (const m of xmlText.matchAll(/<GranicusEvent>([\s\S]*?)<\/GranicusEvent>/g)) {
    const get = tag => (m[1].match(new RegExp(`<${tag}>([\\s\\S]*?)</${tag}>`)) || [])[1]?.trim() || "";
    const ev = normalizeEvent({
      EventId: get("EventId") || null,
      EventBodyName: get("EventBodyName"),
      EventDate: get("EventDate"),
      EventTime: get("EventTime"),
      EventLocation: get("EventLocation")
    }, site);
    if (ev) events.push(ev);
  }
  return events;
}

/* ---------------------------------------------------------
 * Date helpers
 * --------------------------------------------------------- */

// Legistar dates are local (America/Chicago) wall-clock values. Keep them
// that way; do not label them UTC.
function parseLegistarDate(eventDate, eventTime) {
  if (!eventDate) return null;

  const datePart = String(eventDate).split("T")[0];
  if (!/^\d{4}-\d{2}-\d{2}$/.test(datePart)) return null;

  const m = eventTime && String(eventTime).match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
  if (!m) return `${datePart}T00:00:00`;

  let hour = parseInt(m[1], 10);
  const meridian = m[3].toUpperCase();
  if (meridian === "PM" && hour !== 12) hour += 12;
  if (meridian === "AM" && hour === 12) hour = 0;

  return `${datePart}T${String(hour).padStart(2, "0")}:${m[2]}:00`;
}

// Compares the local date against the cutoff by date only, so it needs no
// timezone arithmetic (the 1-day slop is irrelevant for a 30-day window).
function isWithinLast30DaysOrFuture(local) {
  if (!local) return false;
  const dt = new Date(local + "Z");
  if (isNaN(dt.getTime())) return false;

  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - 30);
  return dt >= cutoff;
}
