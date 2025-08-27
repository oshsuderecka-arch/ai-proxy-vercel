export default async function handler(req, res) {
  // CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  // Preflight
  if (req.method === 'OPTIONS') {
    res.status(204).end();
    return;
  }

  if (req.method !== 'POST') {
    res.status(405).json({ ok: false, error: 'Method Not Allowed' });
    return;
  }

  try {
    const body = typeof req.body === 'string' ? JSON.parse(req.body) : (req.body || {});
    const { prompt, messages, model = 'gpt-4o-mini', provider = 'openai' } = body;

    // Выбираем провайдера по переменным окружения
    const OPENAI_API_KEY = process.env.OPENAI_API_KEY || '';
    const GEMINI_API_KEY = process.env.GEMINI_API_KEY || '';

    // Собираем вход для chat-моделей
    const userMessages = messages && Array.isArray(messages)
      ? messages
      : [{ role: 'user', content: prompt || 'Hello!' }];

    let answerText = '';

    if (provider === 'gemini' || (!OPENAI_API_KEY && GEMINI_API_KEY)) {
      // ---------- Gemini ----------
      const gemModel = 'gemini-1.5-flash';
      const url = `https://generativelanguage.googleapis.com/v1beta/models/${gemModel}:generateContent?key=${GEMINI_API_KEY}`;

      const contentText = userMessages.map(m => `${m.role}: ${m.content}`).join('\n');

      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          contents: [{ parts: [{ text: contentText }] }],
          generationConfig: { temperature: 0.6, maxOutputTokens: 400 }
        })
      });

      if (!r.ok) {
        const t = await r.text();
        throw new Error(`Gemini HTTP ${r.status}: ${t}`);
      }

      const j = await r.json();
      answerText = j?.candidates?.[0]?.content?.parts?.[0]?.text || '(пусто)';
      res.json({
        ok: true,
        provider: 'gemini',
        text: answerText
      });
      return;
    } else {
      // ---------- OpenAI ----------
      const r = await fetch('https://api.openai.com/v1/chat/completions', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${OPENAI_API_KEY}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          model,
          messages: userMessages,
          temperature: 0.6
        })
      });

      if (!r.ok) {
        const t = await r.text();
        throw new Error(`OpenAI HTTP ${r.status}: ${t}`);
      }

      const j = await r.json();
      answerText = j?.choices?.[0]?.message?.content || '(пусто)';

      res.json({
        ok: true,
        provider: 'openai',
        text: answerText,
        raw: j
      });
      return;
    }
  } catch (e) {
    res.status(500).json({ ok: false, error: String(e) });
  }
}
