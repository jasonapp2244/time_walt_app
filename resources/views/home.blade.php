@extends('front_end_layout.front_end')

@section('content')
    <!-- Banner Section -->
    <section class="banner">
        <div class="inner-banner container">
            <div class="row">
                <!-- First Column  -->
                <div class="col-left">
                    <div class="col-left-innr">
                        <p class="bnr-sub-heading animate__animated animate__fadeInUp">EXPLORE THE</p>
                        <h1 class="bnr-main-heading animate__animated animate__fadeInUp">TRUTH BEYOND THE HEADLINES.</h1>
                        <p class="bnr-content animate__animated animate__fadeInUp">News from the Other Side exists to
                            challenge the narratives of mainstream media by
                            shedding light on the overlooked, the silenced, and the misrepresented.</p>
                        <div class="button-group">
                            <a href="#" class="bnr-btn animate__animated animate__fadeInUp">EXPLORE THE TRUTHS <i
                                    class="fas fa-arrow-right"></i></a>
                            <a href="#" class="btn bnr-btn-secondary animate__animated animate__fadeInUp">Watch Our
                                Reports >>></a>
                        </div>
                    </div>
                </div>

                <!-- Second Column (Right) -->
                <div class="col-right">
                    <!-- You can add any content or image here if needed -->
                </div>
            </div>
        </div>
    </section>

    <!-- section 1 -->
    <section class="about-section container">
        <div class="about-inner-section">
            <!-- Left Column -->
            <div class="about-left">
                <p class="about-mini-heading animate__animated animate__fadeInUp ">ABOUT US</p>
                <h2 class="about-main-heading animate__animated animate__fadeInUp">MORE <span
                        class="about-highlighted">ABOUT ME</span></h2>

                <div class="about-image-collage">
                    <img src="{{('user/images/Group 2.png')}}" alt="">

                </div>
            </div>

            <!-- Right Column -->
            <div class="about-about-right">
                <h1 class="about-bold-heading">WHAT IS NEWS FROM THE<br> OTHER SIDE?</h1>

                <p class="italic-intro">
                    <em>News from the Other Side isn’t just a news platform—it’s a movement. In a world dominated by
                        controlled
                        narratives and misinformation, we exist to expose the unseen forces shaping our reality.a</em>
                </p>
                <br>
                <p class="italic-intro">
                    <em>We challenge the mainstream, amplify the silenced, and reveal the truth buried beneath propaganda.
                        Our
                        mission is to awaken minds, encourage critical thinking, and shift the world from fear to
                        empowerment.</em>
                </p>

                <p>
                    We uncover:
                </p>

                <ul class="about-check-list">
                    <li><i class="fa-solid fa-check"></i>The hidden hands controlling global events</li>
                    <li><i class="fa-solid fa-check"></i>The truth behind media manipulation</li>
                    <li><i class="fa-solid fa-check"></i>The systems designed to keep you distracted and divided</li>
                </ul>

                <p class="about-description">
                    We are fearless, independent, and unapologetically real. If you're ready to see beyond the veil, you're
                    in the
                    right place.
                </p>

                <button class="about-cta-button">
                    READ OUR STORY <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </section>

    <!-- news section -->

    <!-- news section -->
    <section class="news-section">
        <div class="container">
            <div class="section-header">
                <h2><span class="highlight">RECENTLY</span> ADDED</h2>
                <ul class="tabs">
                    <li class="tab active" data-tab="all">ALL</li>
                    <li class="tab" data-tab="trending">TRENDING</li>
                    <li class="tab" data-tab="international">INTERNATIONAL</li>
                    <li class="tab" data-tab="politics">POLITICS</li>
                    <li class="tab" data-tab="business">BUSINESS</li>
                </ul>
            </div>

            <!-- ALL TAB -->
            <div class="news-content" id="all">
                <div class="featured-card">
                    <img src="{{('user/images/news 1.png')}}" alt="Featured News">
                    <div class="featured-info">
                        <span class="tag">NEWS</span>
                        <h3>The Fate of Software: Where Is Technology Heading in 2025 and Beyond?</h3>
                        <a href="#" class="read-more">READ MORE >>></a>
                    </div>
                </div>

                <div class="news-grid">
                    <div class="news-card">
                        <img src="{{('user/images/news 2.png')}}" alt="">
                        <span class="category">WORLD</span>
                        <h4>The Role of AI and AGI in Military</h4>
                        <div>
                            <button class="news-cta-button">
                                READ MORE <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="news-card">
                        <img src="{{('user/images/news 3.png')}}" alt="">
                        <span class="category">WORLD</span>
                        <h4>DeepSeek AI Landscape 2025</h4>
                        <div>
                            <button class="news-cta-button">
                                READ MORE <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="news-card">
                        <img src="{{('user/images/news 4.png')}}" alt="">
                        <span class="category">WORLD</span>
                        <h4>The Gift of Empowerment</h4>
                        <div>
                            <button class="news-cta-button">
                                READ MORE <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="news-card">
                        <img src="{{('user/images/news 5.png')}}" alt="">
                        <span class="category">WORLD</span>
                        <h4>Technology Beyond 2025</h4>
                        <div>
                            <button class="news-cta-button">
                                READ MORE <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TRENDING -->
            <div class="news-content" id="trending" style="display: none;">
                <div class="news-grid">
                    <div class="news-card">
                        <img src="{{('user/images/news 2.png')}}" alt="">
                        <span class="category">TECH</span>
                        <h4>Trending: AI in 2025</h4>
                        <div>
                            <button class="news-cta-button">
                                READ MORE <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="news-card">
                        <img src="{{('user/images/news 3.png')}}" alt="">
                        <span class="category">SCIENCE</span>
                        <h4>AI & Empowerment</h4>
                        <div>
                            <button class="news-cta-button">
                                READ MORE <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- INTERNATIONAL -->
        <div class="news-content" id="international" style="display: none;">
            <div class="news-grid">
                <div class="news-card">
                    <img src="{{('user/images/news 4.png')}}" alt="">
                    <h4>Global Tech Shifts</h4>
                    <span class="category">WORLD</span>
                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div class="news-card">
                    <img src="{{('user/images/news 5.png')}}" alt="">
                    <span class="category">WORLD</span>
                    <h4>International AI Policies</h4>

                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- POLITICS -->
        <div class="news-content" id="politics" style="display: none;">
            <div class="news-grid">
                <div class="news-card">

                    <img src="{{('user/images/news 3.png')}}" alt="">
                    <span class="category">POLITICS</span>
                    <h4>Politics of AI</h4>

                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div class="news-card">
                    <img src="{{('user/images/news 2.png')}}" alt="">
                    <span class="category">POLITICS</span>
                    <h4>Global Digital Policies</h4>

                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- BUSINESS -->
        <div class="news-content" id="business" style="display: none;">
            <div class="news-grid">
                <div class="news-card">
                    <img src="{{('user/images/news 5.png')}}" alt="">
                    <span class="category">BUSINESS</span>
                    <h4>AI in Startups</h4>

                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div class="news-card">
                    <img src="{{('user/images/news 4.png')}}" alt="">
                    <span class="category">BUSINESS</span>
                    <h4>Technology & Markets</h4>

                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </section>

    <!-- article section -->
    <section id="news-section">
        <div class="inner-section container">

            <!-- Left Column -->
            <div class="column article-left-column">

                <article class="news-article">
                    <img src="{{('user/images/article3.png')}}" alt="Article Image 1" class="article-img" />
                    <h4 class="category">Decentralization & Freedom</h4>
                    <h2 class="article-title">The Carpet Bombing of America: When Cutting Help Becomes the Goal.</h2>
                    <p class="article-description">
                        Not the death company meltdown... programs that touch nearly half the country.
                    </p>
                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </article>

                <article class="news-article">
                    <img src="{{('user/images/article2.png')}}" alt="Article Image 2" class="article-img" />
                    <h4 class="category">Politics</h4>
                    <h2 class="article-title">The Hidden Cost of Government Science Cuts: Losing Decades of Expertise</h2>
                    <p class="article-description">
                        Recent announcements... impact on America’s scientific infrastructure.
                    </p>
                    <div>
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </article>

            </div>

            <!-- Right Column -->
            <div class="column right-column">
                <article class="highlight-article">
                    <img src="{{('user/images/article 1.png')}}" alt="Media Truth" class="highlight-img" />
                    <h2 class="article-title article-main-title">How the Media Softens Truth for the Powerful – and
                        Sharpens It
                        for the Marginalized</h2>
                    <div class="main-btn">
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </article>
            </div>

        </div>
    </section>

    <section>
        <div class="suggest-news">
            <div class="suggest-news-innr container">
                <div class="suggest-news-col1">
                    <h2 class="suggest-news-hdng">Trump Was Just the Trojan Horse: The Real Architects of America’s
                        Collapse are
                    </h2>
                    <img src="{{('user/images/suggest1.png')}}" alt="Media Truth" class="suggest-img" />
                </div>
                <!-- col2 -->
                <div class="suggest-news-col2">
                    <h2 class="suggest-news-hdng">Trump Was Just the Trojan Horse: The Real Architects of America’s
                        Collapse are
                    </h2>
                    <img src="{{('user/images/suggest2.png')}}" alt="Media Truth" class="suggest-img" />
                </div>
                <!-- col3 -->
                <div class="suggest-news-col2">
                    <h2 class="suggest-news-hdng">Trump Was Just the Trojan Horse: The Real Architects of America’s
                        Collapse are
                    </h2>
                    <img src="{{('user/images/suggest3.png')}}" alt="Media Truth" class="suggest-img" />
                </div>
            </div>

        </div>
    </section>
    <!-- article 4col -->
    <section>
        <div class="fourcol-section">
            <div class="fourcol-section-innr container">

                <!-- Column 1 -->
                <div class="fourcol-section-col1">
                    <img src="{{('user/images/four-col1.png')}}" alt="Media Truth" class="fourcol-img" />
                    <h2 class="fourcol-section-hdng">The Cost of War vs Peace</h2>
                    <p class="fourcol-article-description">
                        War and peace represent two vastly different paths for societies, each with profound economic,...
                    </p>
                    <div class="main-btn">
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Column 2 -->
                <div class="fourcol-section-col1">
                    <img src="{{('user/images/four-col2.png')}}" alt="Media Truth" class="fourcol-img" />
                    <h2 class="fourcol-section-hdng">The Cost of War vs Peace</h2>
                    <p class="fourcol-article-description">
                        War and peace represent two vastly different paths for societies, each with profound economic,...
                    </p>
                    <div class="main-btn">
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Column 3 -->
                <div class="fourcol-section-col1">
                    <img src="{{('user/images/four-col3.png')}}" alt="Media Truth" class="fourcol-img" />
                    <h2 class="fourcol-section-hdng">The Cost of War vs Peace</h2>
                    <p class="fourcol-article-description">
                        War and peace represent two vastly different paths for societies, each with profound economic,...
                    </p>
                    <div class="main-btn">
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Column 4 -->
                <div class="fourcol-section-col1">
                    <img src="{{('user/images/four-col4.png')}}')}}" alt="Media Truth" class="fourcol-img" />
                    <h2 class="fourcol-section-hdng">The Cost of War vs Peace</h2>
                    <p class="fourcol-article-description">
                        War and peace represent two vastly different paths for societies, each with profound economic,...
                    </p>
                    <div class="main-btn">
                        <button class="news-cta-button">
                            READ MORE <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </section>
@endsection
