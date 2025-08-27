// api/proxy.js — универсальный прокси к OpenAI/Gemini на Vercel (Node.js)
const allowOrigin = "*"; // при необходимости поставьте свой домен

function send(res, code, data) {
  res.statusCode = code;
  res.setHeader("Content-Type", "application/json; charset=utf-8");
  res.setHeader("Access-Control-Allow-Origin", allowOrigin);
  res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");
  res.end(JSON.stringify(data));
}

async function readBody(req) {
  return await new Promise((resolve, reject) => {
    let raw = "";
    req.on("data", (c) => (raw += c));
    req.on("end", () => resolve(raw));
    req.on("error", reject);
  });
}

export default async function handler(req, res) {
  // CORS preflight
  if (req.method === "OPTIONS") {
    res.setHeader("Access-Control-Allow-Origin", allowOrigin);
    res.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
    res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");
    res.statusCode = 204;
    return res.end();
  }

  if (req.method !== "POST") {
    return send(res, 405, { ok: false, error: "Method Not Allowed" });
  }

  try {
    const raw = await readBody(req);
    const body = raw ? JSON.parse(raw) : {};
    const provider = (body.provider || "openai").toLowerCase(); // 'openai' | 'gemini'
    const prompt = body.prompt || "Hello AI, give me one test question.";

    // ===== OpenAI =====
    if (provider === "openai") {
      const apiKey = process.env.OPENAI_API_KEY;
      if (!apiKey) return send(res, 500, { ok: false, error: "OPENAI_API_KEY is missing" });

      const payload = {
        model: "gpt-4o-mini",
        messages: [{ role: "user", content: prompt }],
        temperature: 0.6
      };

      const r = await fetch("https://api.openai.com/v1/chat/completions", {
        method: "POST",
        headers: {
          "Authorization": `Bearer ${apiKey}`,
          "Content-Type": "application/json"
        },
        body: JSON.stringify(payload)
      });

      const json = await r.json();
      if (!r.ok) return send(res, r.status, { ok: false, error: json });

      // Вернём как есть
      return send(res, 200, { ok: true, provider: "openai", data: json });
    }

    // ===== Gemini =====
    if (provider === "gemini") {
      const apiKey = process.env.GEMINI_API_KEY;
      if (!apiKey) return send(res, 500, { ok: false, error: "GEMINI_API_KEY is missing" });

      const model = "gemini-1.5-flash";
      const url = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`;
      const payload = { contents: [{ parts: [{ text: prompt }] }] };

      const r = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });

      const json = await r.json();
      if (!r.ok) return send(res, r.status, { ok: false, error: json });

      return send(res, 200, { ok: true, provider: "gemini", data: json });
    }

    return send(res, 400, { ok: false, error: "Unknown provider" });
  } catch (e) {
    return send(res, 500, { ok: false, error: String(e) });
  }
}
