/**
 * Dane County Legistar Scraper Worker
 * Version: 1.0.0
 * - Scrapes HTML table from Dane Legistar events page
 * - Converts rows to JSON format compatible with plugin
 * - Cached in Cloudflare for 24 hours
 */

addEventListener('fetch', event => {
  event.respondWith(handleRequest(event.request, event))
})

const DANE_URL = 'https://dane.legistar.com/Calendar.aspx'
const CACHE_TTL = 24 * 60 * 60 // 24 hours in seconds

async function handleRequest(request, event) {
  const cache = caches.default
  const cacheKey = new Request(DANE_URL)

  // Serve cached response if available
  const cached = await cache.match(cacheKey)
  if (cached) return cached

  try {
    const res = await fetch(DANE_URL)
    if (!res.ok) throw new Error(`Failed to fetch Dane Legistar: ${res.status}`)

    const html = await res.text()
    const events = parseDaneHTML(html)

    const body = JSON.stringify({
      source: 'danecounty',
      fetched_count: events.length,
      count: events.length,
      generated: new Date().toISOString(),
      events
    })

    const response = new Response(body, {
      headers: { 'Content-Type': 'application/json' }
    })

    // Cache for 24 hours
    response.headers.append('Cache-Control', `public, max-age=${CACHE_TTL}`)
    event.waitUntil(cache.put(cacheKey, response.clone()))

    return response

  } catch (err) {
    return new Response(JSON.stringify({
      source: 'danecounty',
      fetched_count: 0,
      count: 0,
      generated: new Date().toISOString(),
      events: [],
      error: err.message
    }), {
      headers: { 'Content-Type': 'application/json' }
    })
  }
}

// ---------------------------------------------------------
// HTML parsing
// ---------------------------------------------------------
function parseDaneHTML(html) {
  const events = []

  // Simple DOM parser for Worker (no JSDOM)
  // Extract rows from the main table
  const tableMatch = html.match(/<table[^>]*id="ctl00_ContentPlaceHolder1_gvEvents"[^>]*>([\s\S]*?)<\/table>/i)
  if (!tableMatch) return events

  const rows = tableMatch[1].match(/<tr[^>]*>([\s\S]*?)<\/tr>/gi)
  if (!rows) return events

  rows.forEach((row, i) => {
    if (i === 0) return // skip header

    const cells = row.match(/<td[^>]*>([\s\S]*?)<\/td>/gi)
    if (!cells || cells.length < 3) return

    const dateText = cells[0].replace(/<[^>]+>/g, '').trim()
    const titleCell = cells[1]
    const title = titleCell.replace(/<[^>]+>/g, '').trim()
    const location = cells[2].replace(/<[^>]+>/g, '').trim()

    const linkMatch = titleCell.match(/href="([^"]+)"/i)
    const source_url = linkMatch ? linkMatch[1] : ''

    // Convert date text into ISO string
    const datetime = new Date(dateText).toISOString()

    events.push({
      event_id: `dane-${i}`,
      title,
      datetime,
      location,
      source_url
    })
  })

  return events
}
