// api/index.js - Обновленная версия с поддержкой Gemini
export default async function handler(req, res) {
  const { pathname } = new URL(req.url);
  
  // Маршрутизация запросов
  if (pathname === '/api/openai') {
    // Перенаправляем на OpenAI handler
    const openaiHandler = await import('./openai.js');
    return openaiHandler.default(req, res);
  } 
  else if (pathname === '/api/gemini') {
    // Перенаправляем на Gemini handler
    const geminiHandler = await import('./gemini.js');
    return geminiHandler.default(req, res);
  }
  else if (pathname === '/api/ping') {
    // Health check
    return res.status(200).json({ 
      status: 'ok', 
      timestamp: new Date().toISOString(),
      services: {
        openai: 'available',
        gemini: 'available'
      }
    });
  }
  else {
    // Информация о доступных эндпоинтах
    return res.status(200).json({
      ok: true,
      message: "AI Proxy работает на Vercel",
      time: new Date().toISOString(),
      endpoints: {
        '/api/openai': 'OpenAI API proxy',
        '/api/gemini': 'Gemini API proxy', 
        '/api/ping': 'Health check'
      },
      usage: {
        openai: 'POST /api/openai with OpenAI format',
        gemini: 'POST /api/gemini with OpenAI format (converted to Gemini)'
      }
    });
  }
}
