import { useInfiniteQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import StoryCard from './StoryCard'
import StoryComposer from './StoryComposer'

// The class feed: news and photos from the teachers and the school, newest first (pinned posts on top).
export default function StoriesPage() {
  const { t } = useTranslation()
  const feed = useInfiniteQuery({
    queryKey: ['stories', 'feed'],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => (await api.get('/stories', { params: { page: pageParam } })).data,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  })
  const stories = feed.data?.pages.flatMap((p) => p.data) ?? []
  const canPost = feed.data?.pages[0]?.can_post
  return (
    <section className="mx-auto max-w-2xl space-y-4">
      <h1>{t('stories.title')}</h1>
      {canPost && <StoryComposer />}
      {feed.isLoading ? <p className="text-slate-500">{t('common.loading')}</p> : stories.length === 0 ? (
        <p className="rounded-lg border bg-snow p-8 text-center text-slate-600">{canPost ? t('stories.emptyTeacher') : t('stories.empty')}</p>
      ) : (
        <div className="space-y-4">{stories.map((s) => <StoryCard key={s.id} story={s} />)}</div>
      )}
      {feed.hasNextPage && <div className="text-center"><button type="button" disabled={feed.isFetchingNextPage} className="rounded border px-6 py-2 text-brand disabled:opacity-50" onClick={() => feed.fetchNextPage()}>{t('stories.more')}</button></div>}
    </section>
  )
}
