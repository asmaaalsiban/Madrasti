// Cuts the chosen square (pixels of the original picture, from react-easy-crop) out of the image and returns it as a 512x512 JPEG file.
const OUT = 512

export default async function cropImage(src, area) {
  const img = await new Promise((resolve, reject) => {
    const i = new Image()
    i.onload = () => resolve(i)
    i.onerror = reject
    i.src = src
  })
  const canvas = document.createElement('canvas')
  canvas.width = OUT
  canvas.height = OUT
  const ctx = canvas.getContext('2d')
  ctx.fillStyle = '#fff' // a transparent PNG becomes white, as on the server
  ctx.fillRect(0, 0, OUT, OUT)
  ctx.drawImage(img, area.x, area.y, area.width, area.height, 0, 0, OUT, OUT)
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
  if (!blob) throw new Error('crop failed')
  return new File([blob], 'avatar.jpg', { type: 'image/jpeg' })
}
