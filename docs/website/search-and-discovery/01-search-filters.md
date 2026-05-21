Jetonomy's search finds content across your entire community in real time - topics, replies, spaces, and tags - and lets you narrow results with powerful filters so members always land on exactly what they need.

## What You Will Learn

- How to run a full-text search from anywhere in the community
- What the filter pills do and how to use them
- How advanced filters refine results by date, author, tag, and sort order
- How to show and hide the filter bar
- How developers can extend search filters with a custom hook

## Running a Search

The search bar sits in the community navigation, visible on every page. Type any keyword and press Enter or click the search icon. Jetonomy searches across:

- Topic titles and content
- Reply content
- Space names and descriptions
- Tag names

Results appear on the search results page at `/community/search/`. Each result card shows the content type, the space it belongs to, the author, the date, and a short excerpt with your search term highlighted.

![Search results page](../images/search-results.png)

> **Tip:** Phrase searches work well in Jetonomy. Wrap your query in quotes - `"email digest"` - to find that exact phrase rather than posts containing both words separately.

## Filter Pills

At the top of the results page, four filter pills let you narrow by content type instantly:

| Pill | Shows |
|------|-------|
| All | Every matching result |
| Posts | Topic results only |
| Spaces | Space results only |
| Tags | Tag results only |

Click any pill to filter. The URL updates so you can share a filtered search link with your team.

## Advanced Filters

Click the **Filters** button to expand the advanced filter bar. These filters stack - you can combine them in any combination.

### Date Range

Choose a preset (Last 7 days, Last 30 days, Last year) or set a custom From / To date. Jetonomy filters by the post's original publish date, not its last reply date.

### Author

Type a username to filter results to a specific author. Jetonomy auto-suggests matching members as you type. This is useful for reviewing a particular member's contributions or finding your own older posts.

### Tag

Type a tag name to restrict results to posts that carry that tag. Combining author and tag filters is a fast way to find all posts by a specific member on a specific topic.

### Sort Order

| Option | Orders by |
|--------|-----------|
| Relevance | Full-text match score (default) |
| Newest | Most recently published first |
| Most Voted | Highest net vote score first |

Relevance is the default because it surfaces the best textual match. Switch to Newest when you know you are looking for a recent discussion. Switch to Most Voted when you want the community's highest-rated answer on a topic.

### Collapsing the Filter Bar

Click **Filters** again to collapse the bar. Your active filters remain applied - the pill count badge on the Filters button shows how many filters are currently active.

## For Developers: Extending Search Filters

The `jetonomy_search_filters` hook lets you add custom filter parameters to the search query. This is how Pro extensions like analytics-based filtering hook into the search pipeline.

```php
add_filter( 'jetonomy_search_filters', function( $filters, $query_args ) {
    // Add a custom filter to restrict to a specific space.
    if ( ! empty( $_GET['space_id'] ) ) {
        $filters['space_id'] = absint( $_GET['space_id'] );
    }
    return $filters;
}, 10, 2 );
```

See the [Hooks Reference](../developer-guide/02-hooks-reference.md) for the full parameter list.

## What's Next?

Learn how tags work across spaces and how members can browse tag pages to find related content.

[Tags →](02-tags.md)

## Related Pro Features

- [SEO Pro](../pro-features/14-seo-pro.md) - per-space meta, schema.org markup, OG/Twitter cards, and sitemaps so search engines surface your community.
