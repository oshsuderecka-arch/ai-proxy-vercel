// api/openai.js — CommonJS, безопасный парсинг и понятные ответы
module.exports = async (req, res) => {
  try {
    // Диагностика из браузера
    if (req.method === "GET") {
      res.status(200).json({ ok: true, usage: "POST JSON { model, messages }" });
      return;
    }
    if (req.method !== "POST") {
      res.status(405).json({ error: "Method Not Allowed. Use POST" });
      return;
    }

    // Читаем сырой body (надёжно для serverless)
    let raw = "";
    await new Promise((resolve, reject) => {
      req.on("data", c => (raw += c));
      req.on("end", resolve);
      req.on("error", reject);
    });

    let payload = {};
    if (raw) {
      try { payload = JSON.parse(raw); }
      catch { res.status(400).json({ error: "Invalid JSON in request body" }); return; }
    }

    const key = process.env.OPENAI_API_KEY;
    if (!key) { res.status(500).json({ error: "OPENAI_API_KEY is not set" }); return; }

    const model = payload.model || "gpt-4o-mini";
    const messages = payload.messages || [{ role: "user", content: "Hello!" }];
    const temperature = payload.temperature ?? 0.2;
    const max_tokens = payload.max_tokens;

    const upstream = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${key}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ model, messages, temperature, max_tokens })
    });

    const text = await upstream.text(); // не падаем, если upstream вернул не-JSON
    res.status(upstream.status);
    res.setHeader("Content-Type", "application/json");
    res.end(text);
  } catch (err) {
    res.status(500).json({ error: String(err), hint: "Проверь ключ и что отправляешь POST c JSON" });
  }
};
