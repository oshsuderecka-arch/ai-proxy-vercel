// api/openai.js  (CommonJS, устойчивый парсинг и понятные ответы)
module.exports = async (req, res) => {
  try {
    // Профилактика: разрешим GET для быстрой диагностики в браузере
    if (req.method === "GET") {
      res.status(200).json({ ok: true, usage: "POST JSON {model, messages}" });
      return;
    }
    if (req.method !== "POST") {
      res.status(405).json({ error: "Method Not Allowed. Use POST" });
      return;
    }

    // Надежно читаем тело и валидируем JSON
    let raw = "";
    await new Promise((resolve, reject) => {
      req.on("data", (c) => (raw += c));
      req.on("end", resolve);
      req.on("error", reject);
    });

    let payload = {};
    if (raw) {
      try {
        payload = JSON.parse(raw);
      } catch (e) {
        res.status(400).json({ error: "Invalid JSON in request body" });
        return;
      }
    }

    const key = process.env.OPENAI_API_KEY;
    if (!key) {
      res.status(500).json({ error: "OPENAI_API_KEY is not set" });
      return;
    }

    // Значения по умолчанию
    const model = payload.model || "gpt-4o-mini";
    const messages = payload.messages || [{ role: "user", content: "Hello!" }];
    const temperature = payload.temperature ?? 0.2;
    const max_tokens = payload.max_tokens;

    // Запрос к OpenAI
    const upstream = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${key}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ model, messages, temperature, max_tokens })
    });

    const text = await upstream.text(); // не ломаемся на не-JSON ответах
    res.status(upstream.status);
    res.setHeader("Content-Type", "application/json");
    res.end(text);
  } catch (err) {
    res.status(500).json({
      error: String(err),
      hint: "Проверь OPENAI_API_KEY и что запрос — POST c JSON"
    });
  }
};
