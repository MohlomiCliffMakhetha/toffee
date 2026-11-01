# Who can do what on a channel

## Now (in the app)

On someone else's channel, everyone signed in can:

- **Public tab:** read, react, and reply to posts. Only the channel's owner starts public posts.
- **Private tab:** start a private post to the owner, and reply privately. Private is between two
  people (no groups yet).

On your own channel you can post publicly, reply, and post privately to yourself.

Enforced in `api.php` (`post`): a public post with no parent on someone else's channel is refused.
The comment box only enables Send for what's allowed, with a short hint otherwise.

## Next (agreed in principle, not built)

Circles instead of followers. You only ever move people inward; nobody sees counts.

| On your channel          | Crew (everyone, default) | Squad | Amigos |
|--------------------------|:---:|:---:|:---:|
| Read Public, react       | ✓ | ✓ | ✓ |
| Reply publicly           | ✓ | ✓ | ✓ |
| Reply privately (to you) |   | ✓ | ✓ |
| Start a public post      |   |   | ✓ |
| Start a private post / group, be tagged into one |   |   | ✓ |

Open questions:

- Start with two circles (Everyone + Amigos) and add Squad only if people ask for it?
- Adding someone: one tap and one-way, or does Amigos need both people to agree?
- Names (Crew / Squad / Amigos, or something else).
- An "Open" switch for a feedback channel, where everyone may start public posts.
- Private groups: who can add people, and a size limit.

Keep it simple: the circle is set by tapping a person (no settings screen), and the comment box
only shows what you're allowed to do.
