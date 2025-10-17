module.exports = async (req, res) => {
  if (req.method !== "POST") {
    res.status(405).json({ error: "Method Not Allowed" });
    return;
  }
  try {
    // читаем тело запроса
    let raw = "";
    for await (const chunk of req) raw += chunk;
    const payload = raw ? JSON.parse(raw) : {};

    const key = process.env.OPENAI_API_KEY;
    if (!key) {
      res.status(500).json({ error: "OPENAI_API_KEY is not set" });
      return;
    }

    // минимальная прокся на chat.completions
    const r = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${key}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        model: payload.model || "gpt-4o-mini",
        messages: payload.messages || [{ role: "user", content: "Hello!" }],
        temperature: payload.temperature ?? 0.2,
        max_tokens: payload.max_tokens
      })
    });

    const data = await r.json();
    res.status(r.ok ? 200 : r.status).json(data);
  } catch (e) {
    res.status(500).json({ error: String(e) });
  }
};
