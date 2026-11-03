import useBadges from '../../Badges/useBadges'

// How many announcements the signed-in user has not opened (from the shared counters, refreshed every 30 seconds).
export default function useUnreadAnnouncements() {
  return useBadges().announcements
}
