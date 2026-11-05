export const GREEN = '#15803d'
const AMBER = '#b45309'
const RED = '#b91c1c'

// Green when good, amber when so-so, red when poor.
export const levelColor = (percent) => (percent >= 75 ? GREEN : percent >= 50 ? AMBER : RED)
