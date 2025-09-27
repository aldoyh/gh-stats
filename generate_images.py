#!/usr/bin/python3

import asyncio
import os
import re

import aiohttp

from github_stats import Stats


################################################################################
# Helper Functions
################################################################################

def generate_output_folder() -> None:
    """
    Create the output folder if it does not already exist
    """
    if not os.path.isdir("generated"):
        os.mkdir("generated")


################################################################################
# Individual Image Generation Functions
################################################################################

async def generate_overview(s: Stats) -> None:
    """
    Generate an SVG badge with summary statistics
    :param s: Represents user's GitHub statistics
    """
    try:
        print("Generating overview SVG...")
        
        with open("templates/overview.svg", "r") as f:
            output = f.read()

        # Get stats with error handling
        name = await s.name
        stars = await s.stargazers
        forks = await s.forks
        contributions = await s.total_contributions
        lines_changed = await s.lines_changed
        views = await s.views
        repos = await s.all_repos
        
        # Replace template variables
        output = re.sub("{{ name }}", name, output)
        output = re.sub("{{ stars }}", f"{stars:,}", output)
        output = re.sub("{{ forks }}", f"{forks:,}", output)
        output = re.sub("{{ contributions }}", f"{contributions:,}", output)
        
        changed = lines_changed[0] + lines_changed[1]
        output = re.sub("{{ lines_changed }}", f"{changed:,}", output)
        output = re.sub("{{ views }}", f"{views:,}", output)
        output = re.sub("{{ repos }}", f"{len(repos):,}", output)

        generate_output_folder()
        with open("generated/overview.svg", "w") as f:
            f.write(output)
        
        print("Overview SVG generated successfully!")
        
    except Exception as e:
        print(f"Error generating overview SVG: {e}")
        raise


async def generate_languages(s: Stats) -> None:
    """
    Generate an SVG badge with summary languages used
    :param s: Represents user's GitHub statistics
    """
    try:
        print("Generating languages SVG...")
        
        with open("templates/languages.svg", "r") as f:
            output = f.read()

        progress = ""
        lang_list = ""
        
        # Get languages with error handling
        languages = await s.languages
        sorted_languages = sorted(languages.items(), reverse=True,
                                  key=lambda t: t[1].get("size"))
        
        delay_between = 150
        for i, (lang, data) in enumerate(sorted_languages):
            color = data.get("color")
            color = color if color is not None else "#000000"
            ratio = [.98, .02]
            if data.get("prop", 0) > 50:
                ratio = [.99, .01]
            if i == len(sorted_languages) - 1:
                ratio = [1, 0]
            progress += (f'<span style="background-color: {color};'
                         f'width: {(ratio[0] * data.get("prop", 0)):0.3f}%;'
                         f'margin-right: {(ratio[1] * data.get("prop", 0)):0.3f}%;" '
                         f'class="progress-item"></span>')
            lang_list += f"""
<li style="animation-delay: {i * delay_between}ms;">
<svg xmlns="http://www.w3.org/2000/svg" class="octicon" style="fill:{color};"
viewBox="0 0 16 16" version="1.1" width="16" height="16"><path
fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8z"></path></svg>
<span class="lang">{lang}</span>
<span class="percent">{data.get("prop", 0):0.2f}%</span>
</li>

"""

        output = re.sub(r"{{ progress }}", progress, output)
        output = re.sub(r"{{ lang_list }}", lang_list, output)

        generate_output_folder()
        with open("generated/languages.svg", "w") as f:
            f.write(output)
        
        print("Languages SVG generated successfully!")
        
    except Exception as e:
        print(f"Error generating languages SVG: {e}")
        raise


################################################################################
# Main Function
################################################################################

async def main() -> None:
    """
    Generate all badges
    """
    # Validate required environment variables
    access_token = os.getenv("ACCESS_TOKEN")
    if not access_token:
        # Try fallback to GITHUB_TOKEN as a last resort
        access_token = os.getenv("GITHUB_TOKEN")
        if not access_token:
            raise Exception("ACCESS_TOKEN environment variable is required! Please set it in your repository secrets.")
        else:
            print("Warning: Using GITHUB_TOKEN as fallback. For better functionality, use a personal access token as ACCESS_TOKEN.")
    
    user = os.getenv("GITHUB_ACTOR")
    if not user:
        raise Exception("GITHUB_ACTOR environment variable is required!")
    
    # Validate and process optional environment variables
    exclude_repos = os.getenv("EXCLUDED", "").strip()
    exclude_repos = ({x.strip() for x in exclude_repos.split(",") if x.strip()}
                     if exclude_repos else None)
    
    exclude_langs = os.getenv("EXCLUDED_LANGS", "").strip()
    exclude_langs = ({x.strip() for x in exclude_langs.split(",") if x.strip()}
                     if exclude_langs else None)
    
    # Handle COUNT_STATS_FROM_FORKS more safely
    count_forks_env = os.getenv("COUNT_STATS_FROM_FORKS", "").strip()
    consider_forked_repos = count_forks_env.lower() in ("true", "1", "yes", "on") if count_forks_env else False
    
    print(f"Generating stats for user: {user}")
    print(f"Consider forked repositories: {consider_forked_repos}")
    if exclude_repos:
        print(f"Excluded repositories: {exclude_repos}")
    if exclude_langs:
        print(f"Excluded languages: {exclude_langs}")
    
    try:
        async with aiohttp.ClientSession() as session:
            s = Stats(user, access_token, session, exclude_repos=exclude_repos,
                      exclude_langs=exclude_langs,
                      consider_forked_repos=consider_forked_repos)
            await asyncio.gather(generate_languages(s), generate_overview(s))
        print("Successfully generated all stats images!")
    except Exception as e:
        print(f"Error generating stats: {e}")
        raise


if __name__ == "__main__":
    asyncio.run(main())
