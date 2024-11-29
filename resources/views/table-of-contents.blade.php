<html>
<head></head>
<style type="text/css">
    * {
        font-family: "Courier New";
    }

    .toc-list, .toc-list ol {
        list-style-type: none;
    }

    .toc-list {
        padding: 0;
    }

    .toc-list ol {
        padding-inline-start: 2ch;
    }
</style>
<body>
<!-- role=list necessary because WebKit removes list semantic when list-style-type: none -->
<h2 style="text-align: center;">TABLE OF CONTENTS</h2>
<ol class="toc-list" role="list">
    @foreach ($sections as $section)
        <li>
            <a href="#{{$section['id']}}">
                <span class="title">{{$section['title']}}</span>
                <span class="page">
                    <span class="visually-hidden">Page&nbsp;{{$section['pageNum']}}</span>
                </span>
            </a>
            <ol role="list">
                @foreach ($section['children'] as $children)
                    <li>
                        <a href="#{{$children['id']}}">
                            <span class="title">{{$children['title']}}</span>
                            <span class="page">Page&nbsp;{{$children['pageNum']}}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </li>
    @endforeach
</ol>
</body>
</html>
