/**
 * Glass Government Legistar Worker
 * Version: 1.4.0
 * - JSON first, XML fallback
 * - Cache with debug bypass
 * - HARD 30-day lookback + future events
 * - Debug mode returns full raw API response
 * - Madison returns detailed event objects (agenda, minutes, media, EventInSiteURL)
 * - Proper OData datetime filter
 * - Cloudflare cache 15 min
 */

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const debug = url.searchParams.get("debug") === "1";

    let client;
    if (url.pathname === "/events/madison") client = "madison";
    else if (url.pathname === "/events/dane") client = "danecounty";
    else return new Response("Not found", { status: 404 });

    const cacheKey = new Request(url.origin + url.pathname);
    const cache = caches.default;

    if (!debug) {
      const cached = await cache.match(cacheKey);
      if (cached) return cached;
    }

    // 🔹 OData filter with correct datetime format
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - 30);
    const cutoffDateTime = cutoff.toISOString().split('.')[0]; // YYYY-MM-DDTHH:MM:SS
    const legistarURL = `https://webapi.legistar.com/v1/${client}/events?$orderby=EventDate&$filter=EventDate ge datetime'${cutoffDateTime}'`;

    let events = [];
    try {
      const res = await fetch(legistarURL, {
        headers: {
          "Accept": "application/json",
          "User-Agent": "GlassGovernment/legistar-cache"
        }
      });

      const rawText = await res.text();

      // DEBUG MODE: return raw API response immediately
      if (debug) {
        return new Response(JSON.stringify({
          source: client,
          raw_response: rawText,
          url: legistarURL
        }, null, 2), {
          headers: { "Content-Type": "application/json" }
        });
      }

      let json;
      try { json = JSON.parse(rawText); } catch { json = null; }

      if (client === "madison" && json && Array.isArray(json)) {
        // map full event details for Madison
        events = json.map(e => ({
          event_id: e.EventId,
          guid: e.EventGuid,
          last_modified: e.EventLastModifiedUtc,
          body_name: e.EventBodyName,
          date: e.EventDate,
          time: e.EventTime,
          location: e.EventLocation,
          agenda_file: e.EventAgendaFile,
          minutes_file: e.EventMinutesFile,
          video: e.EventVideoPath || e.EventMedia,
          url: e.EventInSiteURL,
          status: e.EventAgendaStatusName,
          comment: e.EventComment,
          items: e.EventItems || []
        })).filter(Boolean);
      } else if (json && Array.isArray(json.value)) {
        events = json.value.map(e => normalizeEvent(e, client)).filter(Boolean);
      } else if (json && Array.isArray(json)) {
        events = json.map(e => normalizeEvent(e, client)).filter(Boolean);
      } else if (rawText.includes('<GranicusEvent>')) {
        events = normalizeXML(rawText, client);
      } else if (rawText.includes('<Error>')) {
        const excMatch = rawText.match(/<ExceptionMessage>([\s\S]*?)<\/ExceptionMessage>/);
        const excMsg = excMatch ? excMatch[1].trim() : 'Unknown Legistar API error';
        if (debug) console.log(`Legistar API error for ${client}: ${excMsg}`);
      }

      // 🔒 HARD FILTER: last 30 days + future
      events = events.filter(e => e && isWithinLast30DaysOrFuture(e.date || e.datetime));

      if (debug) console.log(`Worker v1.4.0: returned ${events.length} events for ${client}`);

    } catch (err) {
      if (debug) console.log('Worker fetch/parsing error:', err);
      events = [];
    }

    const body = JSON.stringify({
      source: client,
      fetched_count: events.length,
      count: events.length,
      generated: new Date().toISOString(),
      events
    });

    const response = new Response(body, {
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": debug ? "no-store" : "public, max-age=900" // 15 min cache
      }
    });

    if (!debug) ctx.waitUntil(cache.put(cacheKey, response.clone()));
    return response;
  }
};

/* ---------------------------------------------------------
 * Normalizers
 * --------------------------------------------------------- */

function normalizeEvent(e, client) {
  const dateField = e.EventDate || e.StartDate || e.MeetingDate;
  const timeField = e.EventTime || e.StartTime || e.MeetingTime;
  const dt = parseLegistarDate(dateField, timeField);

  if (!dt) return null;

  return {
    event_id: String(e.EventId || e.ID || "unknown"),
    title: e.EventBodyName || e.MeetingName || "Meeting",
    datetime: dt,
    location: e.EventLocation || e.MeetingLocation || "",
    source_url: `https://${client}.legistar.com/MeetingDetail.aspx?ID=${e.EventId || e.ID || ""}`
  };
}

function normalizeXML(xmlText, client) {
  const events = [];
  const matches = [...xmlText.matchAll(/<GranicusEvent>([\s\S]*?)<\/GranicusEvent>/g)];

  for (const m of matches) {
    const get = tag =>
      (m[1].match(new RegExp(`<${tag}>(.*?)<\/${tag}>`)) || [])[1] || "";

    const dt = parseLegistarDate(get("EventDate"), get("EventTime"));
    if (!dt) continue;

    events.push({
      event_id: get("EventId"),
      title: get("EventBodyName") || "Meeting",
      datetime: dt,
      location: get("EventLocation") || "",
      source_url: `https://${client}.legistar.com/MeetingDetail.aspx?ID=${get("EventId")}`
    });
  }

  return events;
}

/* ---------------------------------------------------------
 * Date helpers
 * --------------------------------------------------------- */

function parseLegistarDate(eventDate, eventTime) {
  if (!eventDate) return null;

  const datePart = eventDate.split("T")[0];
  if (!eventTime) return `${datePart}T12:00:00Z`;

  const m = eventTime.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
  if (!m) return `${datePart}T12:00:00Z`;

  let hour = parseInt(m[1], 10);
  const minute = m[2];
  const meridian = m[3].toUpperCase();

  if (meridian === "PM" && hour !== 12) hour += 12;
  if (meridian === "AM" && hour === 12) hour = 0;

  return `${datePart}T${String(hour).padStart(2, "0")}:${minute}:00Z`;
}

function isWithinLast30DaysOrFuture(iso) {
  if (!iso) return false;
  const dt = new Date(iso);
  if (isNaN(dt.getTime())) return false;

  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - 30);
  return dt >= cutoff;
}
