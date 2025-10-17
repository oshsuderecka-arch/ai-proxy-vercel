export default async function handler(req, res) {
  let body = "";
  for await (const chunk of req) body += chunk;
  res.status(200).json({
    method: req.method,
    body: body ? JSON.parse(body) : null
  });
}

