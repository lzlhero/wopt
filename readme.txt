* 环境要求 *

Windows，Linux，Mac 都支持，但需要 PHP(>5.3) 及 Java 环境



* 使用方法 *

1. 在源项目的模板中，将欲合并的 script 和 link 标记添加 data-build-id={id} 属性。
2. 在源项目的模板中，通过 <!-- {id} --> 设置好合并资源的注释插入点。

假设有 chat.html 文件，添加“注释插入点”与“data-build-id”属性后，如下：

<!-- chat.all.css -->
<link href="{$static_url}/core/js/jcrop/default/jcrop.css" rel="stylesheet" type="text/css" data-build-id="chat.all.css"/>
<link href="{$static_url}/core/js/jplayer/default/jplayer.css" rel="stylesheet" type="text/css" data-build-id="chat.all.css"/>
<link href="{$static_url}/chat/css/chat.css" rel="stylesheet" type="text/css" data-build-id="chat.all.css"/>

...

<!-- chat.all.js -->
<script type="text/javascript" src="{$static_url}/core/js/jcrop/jquery.jcrop.js" data-build-id="chat.all.js"></script>
<script type="text/javascript" src="{$static_url}/core/js/jplayer/jquery.jplayer.js" data-build-id="chat.all.js"></script>
<script type="text/javascript" src="{$static_url}/core/js/soundmanager2/soundmanager2.js" data-build-id="chat.all.js"></script>
<script type="text/javascript" src="{$static_url}/chat/js/chat.js" data-build-id="chat.all.js"></script>


3. 编写 config.json 文件。

根据上面的 chat 项目，其格式如下：

{
	// copy info, windows path must like "c:\\path\\to\\project"
	"source_dir": "/Users/lvzhiliang/htdocs/zhisland/zhisland-webim-dev",
	"target_dir": "/Users/lvzhiliang/htdocs/php/web-optimizer/webim",
	"copy_exclude_patterns" : [".svn", ".DS_Store"],

	// scan template files list.
	"scan_include_paths": [
		"webroot/application/views"
	],
	"scan_include_patterns": ["*.html"],

	// scan contents info.
	"scan_to_build": [
		{
			"id": "chat.all.css",
			"path": "webroot/static/chat/css/chat.all.css",
			"html": "<link href=\"{$static_url}/chat/css/chat.all.css\" rel=\"stylesheet\" type=\"text/css\" />"
		},
		{
			"id": "chat.all.js",
			"path": "webroot/static/chat/js/chat.all.js",
			"html": "<script src=\"{$static_url}/chat/js/chat.all.js\" type=\"text/javascript\"></script>"
		}
	],

	// url mapping for web virtual paths.
	"url_mapping": [
		{
			"url_path": "{$static_url}",
			"local_path": "webroot/static"
		}
	]
}


4. 代码优化的运行

A. php wopt.php <config_file>
B. php wopt.php <source_dir> <target_dir> <config_file>

命令行中的 <source_dir> 与 <target_dir> 会覆盖 config_file 中的 source_dir 与 target_dir 属性。

5. 日志

每次运行完 wopt 后都会生成 wopt.log 文件，里面有日志信息。
