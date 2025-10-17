export default function handler(_req, res) {
  res.status(200).json({ pong: true, t: Date.now() });
}
