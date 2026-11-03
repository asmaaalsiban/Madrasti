import useBadges from '../../Badges/useBadges'

// How many chat messages the signed-in user has not read (from the shared counters, refreshed every 30 seconds).
export default function useUnreadChat() {
  return useBadges().chat
}
