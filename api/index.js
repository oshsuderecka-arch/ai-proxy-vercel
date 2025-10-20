// api/index.js
export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();

  const { pathname } = new URL(req.url);

  try {
    if (pathname === '/api/openai') {
      const openaiHandler = await import('./openai.js');
      return openaiHandler.default(req, res);
    } 
    else if (pathname === '/api/gemini') {
      const geminiHandler = await import('./gemini.js');
      return geminiHandler.default(req, res);
    } 
    else if (pathname === '/api/ping') {
      return res.status(200).json({
        status: 'ok',
        timestamp: new Date().toISOString(),
        services: {
          openai: 'available',
          gemini: 'available',
        },
      });
    } 
    else {
      return res.status(200).json({
        ok: true,
        message: 'AI Proxy работает на Vercel',
        time: new Date().toISOString(),
        endpoints: {
          '/api/openai': 'OpenAI API proxy',
          '/api/gemini': 'Gemini API proxy',
          '/api/ping': 'Health check',
        },
        usage: {
          openai: 'POST /api/openai with OpenAI format',
          gemini: 'POST /api/gemini with OpenAI format (converted to Gemini)',
        },
      });
    }
  } catch (err) {
    return res.status(500).json({ error: 'Internal Server Error', details: err.message });
  }
}
