export default function handler(req, res) {
  res.status(200).json({
    ok: true,
    message: " API работает на Vercel",
    time: new Date().toISOString()
  });
}
 
